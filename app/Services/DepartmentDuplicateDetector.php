<?php

namespace App\Services;

use App\Models\Department;
use App\Support\DepartmentNameNormalizer;
use Illuminate\Support\Collection;

/**
 * Finds departments that are really the same department.
 *
 * Tiers:
 *   EXACT   identical trimmed name_ar (or name_en, case-insensitive).
 *   HIGH    identical after DepartmentNameNormalizer::key() — spacing,
 *           punctuation, tatweel, hamza/alef variants, invisible characters.
 *   REVIEW  equal only after the loose fold, or textually very similar.
 *           NEVER merged automatically; reported for a human decision.
 *
 * EXACT and HIGH pairs are transitively grouped (A~B, B~C => {A,B,C}) because
 * they are safe to act on. REVIEW pairs stay pairs.
 *
 * Archived departments are already-merged leftovers and are excluded unless
 * asked for.
 */
final class DepartmentDuplicateDetector
{
    public const EXACT = 'exact';
    public const HIGH = 'high';
    public const REVIEW = 'review';

    private const MIN_KEY_LENGTH = 3;
    private const REVIEW_SIMILARITY = 90.0;

    /**
     * @return Collection<int, array{tier: string, reasons: list<string>, departments: Collection<int, Department>}>
     */
    public function detect(bool $includeArchived = false): Collection
    {
        $departments = Department::query()
            ->when(! $includeArchived, fn ($q) => $q->where('status', '!=', 'archived'))
            ->orderBy('id')
            ->get()
            ->keyBy('id');

        /** @var array<int, array{ar: string, en: string, arKey: string, enKey: string, arLoose: string, enLoose: string}> $names */
        $names = [];
        foreach ($departments as $d) {
            $ar = trim((string) ($d->name_ar ?: $d->name));
            $en = trim((string) $d->name_en);
            $names[$d->id] = [
                'ar' => $ar,
                'en' => $en,
                'arKey' => DepartmentNameNormalizer::key($ar),
                'enKey' => DepartmentNameNormalizer::key($en),
                'arLoose' => DepartmentNameNormalizer::looseKey($ar),
                'enLoose' => DepartmentNameNormalizer::looseKey($en),
            ];
        }

        $ids = $departments->keys()->all();
        $parent = array_combine($ids, $ids) ?: [];
        $find = function (int $x) use (&$parent, &$find): int {
            return $parent[$x] === $x ? $x : ($parent[$x] = $find($parent[$x]));
        };

        $linkReasons = [];   // root-agnostic: pairKey => [tier, reason]
        $reviewPairs = [];

        for ($i = 0; $i < count($ids); $i++) {
            for ($j = $i + 1; $j < count($ids); $j++) {
                [$a, $b] = [$ids[$i], $ids[$j]];
                $hit = $this->compare($names[$a], $names[$b]);
                if ($hit === null) {
                    continue;
                }

                if ($hit['tier'] === self::REVIEW) {
                    $reviewPairs[] = [$a, $b, $hit['reason']];

                    continue;
                }

                $linkReasons[] = [$a, $b, $hit['tier'], $hit['reason']];
                $parent[$find($a)] = $find($b);
            }
        }

        $buckets = [];
        foreach ($ids as $id) {
            $buckets[$find($id)][] = $id;
        }

        $groups = collect();
        foreach ($buckets as $memberIds) {
            if (count($memberIds) < 2) {
                continue;
            }

            $links = array_filter($linkReasons, fn ($l) => in_array($l[0], $memberIds, true));
            $tier = collect($links)->every(fn ($l) => $l[2] === self::EXACT) ? self::EXACT : self::HIGH;

            $groups->push([
                'tier' => $tier,
                'reasons' => collect($links)->pluck(3)->unique()->values()->all(),
                'departments' => collect($memberIds)->map(fn ($id) => $departments[$id])->values(),
            ]);
        }

        $grouped = collect($buckets)->filter(fn ($m) => count($m) > 1)->flatten()->all();
        foreach ($reviewPairs as [$a, $b, $reason]) {
            // A pair already inside one safe group is not a separate finding.
            if (in_array($a, $grouped, true) && in_array($b, $grouped, true) && $find($a) === $find($b)) {
                continue;
            }

            $groups->push([
                'tier' => self::REVIEW,
                'reasons' => [$reason],
                'departments' => collect([$departments[$a], $departments[$b]]),
            ]);
        }

        return $groups->sortBy(fn ($g) => [match ($g['tier']) {
            self::EXACT => 0, self::HIGH => 1, default => 2,
        }, $g['departments']->first()->id])->values();
    }

    /**
     * The safe (EXACT/HIGH) group containing every department whose name
     * matches $name, or null. Used by `--name=`.
     */
    public function groupForName(string $name, bool $includeArchived = false): ?array
    {
        $key = DepartmentNameNormalizer::key($name);
        if (mb_strlen($key) < self::MIN_KEY_LENGTH) {
            return null;
        }

        return $this->detect($includeArchived)
            ->filter(fn ($g) => $g['tier'] !== self::REVIEW)
            ->first(fn ($g) => $g['departments']->contains(
                fn (Department $d) => DepartmentNameNormalizer::key($d->name_ar ?: $d->name) === $key
                    || DepartmentNameNormalizer::key($d->name_en) === $key
            ));
    }

    /**
     * Would a department with these names collide with an existing,
     * non-archived one? Used to block creating a new duplicate.
     *
     * @return Department|null the conflicting department
     */
    public function findConflict(?string $nameAr, ?string $nameEn = null, ?int $ignoreId = null): ?Department
    {
        $arKey = DepartmentNameNormalizer::key($nameAr);
        $enKey = DepartmentNameNormalizer::key($nameEn);

        return Department::query()
            ->where('status', '!=', 'archived')
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->get()
            ->first(function (Department $d) use ($arKey, $enKey) {
                $dAr = DepartmentNameNormalizer::key($d->name_ar ?: $d->name);
                $dEn = DepartmentNameNormalizer::key($d->name_en);

                return ($this->usable($arKey) && $arKey === $dAr)
                    || ($this->usable($enKey) && $enKey === $dEn);
            });
    }

    private function usable(string $key): bool
    {
        return mb_strlen($key) >= self::MIN_KEY_LENGTH;
    }

    /** @return array{tier: string, reason: string}|null */
    private function compare(array $a, array $b): ?array
    {
        if ($a['ar'] !== '' && $a['ar'] === $b['ar']) {
            return ['tier' => self::EXACT, 'reason' => 'identical name_ar'];
        }
        if ($a['en'] !== '' && mb_strtolower($a['en']) === mb_strtolower($b['en'])) {
            return ['tier' => self::EXACT, 'reason' => 'identical name_en'];
        }

        if ($this->usable($a['arKey']) && $a['arKey'] === $b['arKey']) {
            return ['tier' => self::HIGH, 'reason' => 'name_ar equal after normalization'];
        }
        if ($this->usable($a['enKey']) && $a['enKey'] === $b['enKey']) {
            return ['tier' => self::HIGH, 'reason' => 'name_en equal after normalization'];
        }

        if ($this->usable($a['arLoose']) && $a['arLoose'] === $b['arLoose']) {
            return ['tier' => self::REVIEW, 'reason' => 'name_ar equal only after loose letter folding (taa marbuta / hamza)'];
        }
        if ($this->usable($a['enLoose']) && $a['enLoose'] === $b['enLoose']) {
            return ['tier' => self::REVIEW, 'reason' => 'name_en equal only after loose letter folding'];
        }

        if ($this->usable($a['arKey']) && $this->usable($b['arKey'])
            && DepartmentNameNormalizer::similarity($a['arKey'], $b['arKey']) >= self::REVIEW_SIMILARITY) {
            return ['tier' => self::REVIEW, 'reason' => 'name_ar very similar (>= '.self::REVIEW_SIMILARITY.'%)'];
        }

        return null;
    }
}
