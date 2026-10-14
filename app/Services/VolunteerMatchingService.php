<?php

namespace App\Services;

use App\Models\Department;
use App\Models\HrRequest;
use App\Models\VolunteerOpportunity;
use App\Models\VolunteerRequest;
use App\Support\SkillText;
use Carbon\CarbonImmutable;

/**
 * Transparent, deterministic matching of volunteer applications against an HR staffing request.
 *
 * No AI, no external service, nothing persisted: the score is computed on demand from the
 * fields both sides really have, and every point can be explained.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * SCORING (weights as specified)
 * ─────────────────────────────────────────────────────────────────────────
 *   skills            40   the request's skill phrases found in the volunteer's skills (full credit)
 *                          or in their experience/motivation text (60% credit)
 *   department        20   volunteer's desired department vs the requesting department
 *   experience        15   10 relevance of previous experience to the request, 5 level fit
 *   availability      15   NOT scored: a staffing request has no availability field
 *   language/location 10   NOT scored: a staffing request has no language or location field
 *
 * A criterion that cannot be evaluated is left out and the rest is rescaled to 0–100, so a
 * missing field never silently caps every candidate. The skipped criteria are reported in the
 * response so HR knows what the score does not cover.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * PRIVACY
 * ─────────────────────────────────────────────────────────────────────────
 * Results carry the name, status, the volunteer's own skills/department/level/availability
 * summary and the explanation. Never an email, phone, CV, internal note, reviewer note or the
 * raw application. HR opens the application page for those, under that page's own authorization.
 */
class VolunteerMatchingService
{
    public const DEFAULT_MIN_SCORE = 45;
    public const MAX_RESULTS = 20;
    /** Applications read per run: bounded, newest first. */
    private const POOL = 500;

    /** Statuses worth matching: still open, or accepted but not yet placed. */
    private const CANDIDATE_STATUSES = ['pending', 'reviewing', 'reviewed', 'accepted', 'contacted'];

    public const STATUS_LABELS = [
        'pending' => 'جديد',
        'reviewing' => 'قيد المراجعة',
        'reviewed' => 'تمت المراجعة',
        'accepted' => 'مقبول',
        'contacted' => 'تم التواصل',
        'rejected' => 'مرفوض',
        'converted_to_member' => 'تحوّل إلى عضو',
    ];

    private const GENERIC_DEPT_WORDS = ['اداره', 'قسم', 'فريق', 'مركز', 'وحده'];

    public static function label(int $score): string
    {
        return match (true) {
            $score >= 85 => 'مناسب جدًا',
            $score >= 65 => 'مناسب',
            $score >= 45 => 'يحتاج مراجعة',
            default => 'ضعيف',
        };
    }

    /**
     * @return array{candidates: array<int, array<string, mixed>>, meta: array<string, mixed>}
     */
    public function match(HrRequest $request, int $minScore = self::DEFAULT_MIN_SCORE): array
    {
        $minScore = max(0, min(100, $minScore));
        $request->loadMissing('department:id,name,name_ar,slug');

        $profile = $this->requestProfile($request);
        $limitations = $this->limitations($profile);

        // People who applied directly to THIS request's opportunity are in the same pool (an
        // application is a volunteer request), so they appear once and are only labelled; the link
        // comes from the stored opportunity -> request key, never from text.
        $directOpportunityIds = VolunteerOpportunity::query()->where('incoming_request_id', $request->id)->pluck('id')->all();

        $pool = VolunteerRequest::query()
            ->whereIn('status', self::CANDIDATE_STATUSES)
            ->orderByDesc('created_at')->limit(self::POOL)
            ->get(['id', 'opportunity_id', 'full_name', 'desired_department', 'experience_level', 'skills', 'availability', 'motivation', 'previous_experience', 'status', 'created_at']);

        $out = [];
        if ($profile['max'] > 0) {
            foreach ($pool as $v) {
                $c = $this->score($profile, $v, $directOpportunityIds);
                if ($c['score'] >= $minScore) {
                    $out[] = $c;
                }
            }
            usort($out, fn ($a, $b) => [$b['score'], $b['created_at']] <=> [$a['score'], $a['created_at']]);
            $out = array_slice($out, 0, self::MAX_RESULTS);
        }

        return [
            'candidates' => array_map(function (array $c) {
                unset($c['created_at']);

                return $c;
            }, $out),
            'meta' => [
                'request_id' => $request->id,
                'min_score' => $minScore,
                'evaluated' => $profile['max'] > 0 ? $pool->count() : 0,
                'returned' => count($out),
                'direct_applicants_in_results' => count(array_filter($out, fn ($c) => $c['source'] === 'direct_application')),
                'generated_at' => now()->toIso8601String(),
                'persisted' => false,
                'criteria_evaluated' => array_values(array_keys(array_filter($profile['evaluable']))),
                'limitations' => $limitations,
            ],
        ];
    }

    // ── Request side ─────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function requestProfile(HrRequest $r): array
    {
        $phrases = array_map(fn (string $display) => [
            'display' => $display,
            'norm' => SkillText::normalize($display),
            'keywords' => SkillText::keywords($display),
        ], SkillText::phrases($r->skills_and_specs));
        $phrases = array_values(array_filter($phrases, fn ($p) => $p['keywords'] !== []));

        $dept = $r->department;
        $deptNames = $dept ? array_values(array_filter([SkillText::normalize($dept->name_ar), SkillText::normalize($dept->name), SkillText::normalize($dept->slug)])) : [];
        $deptKeywords = array_values(array_diff(
            array_unique(array_merge(...array_map(fn ($n) => SkillText::keywords($n), $deptNames ?: ['']))),
            self::GENERIC_DEPT_WORDS,
        ));

        $allKeywords = SkillText::keywords(trim($r->skills_and_specs.' '.$r->notes.' '.$r->request_type));
        $level = $this->levelOf(SkillText::normalize($r->skills_and_specs.' '.$r->notes));

        $evaluable = [
            'skills' => $phrases !== [],
            'department' => $deptNames !== [],
            'experience' => $allKeywords !== [],
            'level' => $level !== null,
        ];
        $weights = ['skills' => 40, 'department' => 20, 'experience' => 10, 'level' => 5];
        $max = array_sum(array_map(fn ($k) => $evaluable[$k] ? $weights[$k] : 0, array_keys($weights)));

        return compact('phrases', 'deptNames', 'deptKeywords', 'allKeywords', 'level', 'evaluable', 'weights', 'max');
    }

    /** @param array<string, mixed> $p @return array<int, string> */
    private function limitations(array $p): array
    {
        $out = ['لا يحتوي الطلب على حقل للتوفر، لذلك لم يُحتسب التوفر في الدرجة.', 'لا يحتوي الطلب على اللغة أو الموقع، لذلك لم تُحتسب في الدرجة.'];
        if (! $p['evaluable']['skills']) {
            $out[] = 'لم يُذكر في الطلب مهارات واضحة، لذلك لم تُحتسب المهارات.';
        }
        if ($p['max'] === 0) {
            $out[] = 'لا تكفي بيانات الطلب للمطابقة. أضف المهارات والمواصفات المطلوبة.';
        }

        return $out;
    }

    // ── Candidate side ───────────────────────────────────────────────────────

    /** @param array<string, mixed> $p @param array<int, int> $directOpportunityIds @return array<string, mixed> */
    private function score(array $p, VolunteerRequest $v, array $directOpportunityIds = []): array
    {
        $skillsNorm = SkillText::normalize($v->skills);
        $skillsWords = SkillText::keywords($v->skills);
        $expNorm = SkillText::normalize($v->previous_experience.' '.$v->motivation);
        $expWords = SkillText::keywords($v->previous_experience.' '.$v->motivation);

        $earned = ['skills' => 0.0, 'department' => 0.0, 'experience' => 0.0, 'level' => 0.0];
        $matched = [];
        $missing = [];
        $reasons = [];
        $warnings = [];

        // Skills (40)
        if ($p['evaluable']['skills']) {
            $sum = 0.0;
            $inExperience = [];
            foreach ($p['phrases'] as $ph) {
                $strength = $this->phraseStrength($ph, $skillsNorm, $skillsWords, $expNorm, $expWords);
                if ($strength > 0) {
                    $matched[] = $ph['display'];
                    if ($strength < 1) {
                        $inExperience[] = $ph['display'];
                    }
                } else {
                    $missing[] = $ph['display'];
                }
                $sum += $strength;
            }
            $earned['skills'] = $p['weights']['skills'] * $sum / count($p['phrases']);
            if ($matched !== []) {
                $reasons[] = 'يمتلك مهارات مطابقة: '.implode('، ', array_slice($matched, 0, 4)).'.';
            }
            if ($inExperience !== []) {
                $reasons[] = 'ذكر خبرة مرتبطة بـ: '.implode('، ', array_slice($inExperience, 0, 3)).'.';
            }
        }

        // Department (20)
        if ($p['evaluable']['department']) {
            $volDept = SkillText::normalize($v->desired_department);
            $dept = $this->departmentFit($p, $volDept, $skillsNorm.' '.$expNorm);
            $earned['department'] = $p['weights']['department'] * $dept['fraction'];
            if ($dept['reason']) {
                $reasons[] = $dept['reason'];
            }
        }

        // Experience relevance (10)
        if ($p['evaluable']['experience'] && $expWords !== []) {
            $hits = 0;
            foreach (array_slice($p['allKeywords'], 0, 12) as $kw) {
                foreach ($expWords as $w) {
                    if (SkillText::sameWord($kw, $w)) {
                        $hits++;
                        break;
                    }
                }
            }
            $ratio = min(1, $hits / max(1, min(count($p['allKeywords']), 6)));
            $earned['experience'] = $p['weights']['experience'] * $ratio;
            if ($ratio >= 0.5) {
                $reasons[] = 'خبرته السابقة تتقاطع مع متطلبات الطلب.';
            }
        }

        // Level fit (5)
        if ($p['evaluable']['level']) {
            $volLevel = $this->levelOf(SkillText::normalize($v->experience_level));
            if ($volLevel === null) {
                $warnings[] = 'مستوى خبرته غير محدد بوضوح.';
            } elseif ($volLevel >= $p['level']) {
                $earned['level'] = $p['weights']['level'];
                $reasons[] = 'مستوى خبرته يلبّي المستوى المطلوب.';
            } elseif ($volLevel === $p['level'] - 1) {
                $earned['level'] = $p['weights']['level'] * 0.4;
                $warnings[] = 'مستوى خبرته أقل بدرجة من المطلوب.';
            } else {
                $warnings[] = 'مستوى خبرته أقل من المطلوب.';
            }
        }

        $total = array_sum($earned);
        $score = (int) round(100 * $total / max(1, $p['max']));
        $score = max(0, min(100, $score));

        if ($v->status === 'accepted') {
            $warnings[] = 'طلبه مقبول مسبقًا ولم يُسنَد بعد.';
        } elseif ($v->status === 'contacted') {
            $warnings[] = 'سبق التواصل معه.';
        }
        if ($v->created_at && $v->created_at->lt(CarbonImmutable::now()->subDays(180))) {
            $warnings[] = 'الطلب قديم (أكثر من 180 يومًا)، تأكد من أنه ما زال متاحًا.';
        }
        if ($missing !== [] && $matched !== []) {
            $warnings[] = 'ينقصه: '.implode('، ', array_slice($missing, 0, 3)).'.';
        }

        $breakdown = [];
        foreach (['skills' => 'المهارات', 'department' => 'المجال', 'experience' => 'الخبرة', 'level' => 'مستوى الخبرة'] as $k => $label) {
            if ($p['evaluable'][$k]) {
                $breakdown[] = ['key' => $k, 'label' => $label, 'earned' => round($earned[$k], 1), 'max' => $p['weights'][$k]];
            }
        }

        return [
            'id' => $v->id,
            'name' => $v->full_name,
            'status' => $v->status,
            'status_label' => self::STATUS_LABELS[$v->status] ?? 'غير محدد',
            'score' => $score,
            'label' => self::label($score),
            'matched_skills' => $matched,
            'missing_skills' => $missing,
            'reasons' => $reasons,
            'warnings' => $warnings,
            'breakdown' => $breakdown,
            // Where the candidate came from: a suggestion of this matcher, or an applicant of the
            // opportunity created from this very request. Never both, never duplicated.
            'source' => $v->opportunity_id !== null && in_array((int) $v->opportunity_id, $directOpportunityIds, true) ? 'direct_application' : 'smart_match',
            'source_label' => $v->opportunity_id !== null && in_array((int) $v->opportunity_id, $directOpportunityIds, true) ? 'تقدّم مباشرة على الفرصة' : 'اقتراح من المطابقة الذكية',
            'desired_department' => $v->desired_department,
            'experience_level' => $v->experience_level,
            'availability' => $v->availability,
            'url' => '/dashboard/hr/volunteer-requests/'.$v->id,
            'action_label' => 'مراجعة المرشح',
            'created_at' => $v->created_at?->toIso8601String() ?? '',
        ];
    }

    /**
     * 1.0 in the volunteer's skills, 0.6 in their experience/motivation text, else 0.
     *
     * @param  array{norm: string, keywords: array<int, string>}  $ph
     * @param  array<int, string>  $skillsWords
     * @param  array<int, string>  $expWords
     */
    private function phraseStrength(array $ph, string $skillsNorm, array $skillsWords, string $expNorm, array $expWords): float
    {
        if ($this->contains($skillsNorm, $skillsWords, $ph)) {
            return 1.0;
        }

        return $this->contains($expNorm, $expWords, $ph) ? 0.6 : 0.0;
    }

    /** @param array{norm: string, keywords: array<int, string>} $ph @param array<int, string> $words */
    private function contains(string $haystack, array $words, array $ph): bool
    {
        if ($haystack === '') {
            return false;
        }
        if ($ph['norm'] !== '' && str_contains($haystack, $ph['norm'])) {
            return true;
        }
        $hit = 0;
        foreach ($ph['keywords'] as $kw) {
            foreach ($words as $w) {
                if (SkillText::sameWord($kw, $w)) {
                    $hit++;
                    break;
                }
            }
        }

        return $hit > 0 && $hit >= (int) ceil(count($ph['keywords']) * 0.6);
    }

    /** @param array<string, mixed> $p @return array{fraction: float, reason: string|null} */
    private function departmentFit(array $p, string $volDept, string $volText): array
    {
        if ($volDept === '') {
            return ['fraction' => 0.0, 'reason' => null];
        }
        foreach ($p['deptNames'] as $name) {
            if ($name === $volDept || (mb_strlen($volDept) >= 3 && str_contains($name, $volDept)) || (mb_strlen($name) >= 3 && str_contains($volDept, $name))) {
                return ['fraction' => 1.0, 'reason' => 'اختار نفس مجال القسم المطلوب.'];
            }
        }
        $volWords = SkillText::keywords($volDept);
        foreach ($p['deptKeywords'] as $kw) {
            foreach ($volWords as $w) {
                if (SkillText::sameWord($kw, $w)) {
                    return ['fraction' => 0.6, 'reason' => 'اهتمامه قريب من مجال القسم المطلوب.'];
                }
            }
        }
        foreach ($p['deptKeywords'] as $kw) {
            if ($kw !== '' && str_contains($volText, $kw)) {
                return ['fraction' => 0.3, 'reason' => 'يرتبط ما ذكره من مهارات وخبرات بمجال القسم.'];
            }
        }

        return ['fraction' => 0.0, 'reason' => null];
    }

    /** 1 beginner, 2 intermediate, 3 advanced; null when no level is stated. */
    private function levelOf(string $normalized): ?int
    {
        return match (true) {
            $normalized === '' => null,
            (bool) preg_match('/متقدم|خبير|محترف|سنيور|advanced|expert|senior/u', $normalized) => 3,
            (bool) preg_match('/متوسط|intermediate|mid/u', $normalized) => 2,
            (bool) preg_match('/مبتدي|ناشي|بدايه|beginner|junior|entry/u', $normalized) => 1,
            default => null,
        };
    }
}
