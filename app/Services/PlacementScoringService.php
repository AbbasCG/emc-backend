<?php

namespace App\Services;

use App\Models\PlacementTest;

class PlacementScoringService
{
    /**
     * CEFR level bands.
     * Format: [min_score, max_score, code, en_label, ar_label]
     */
    public const LEVEL_MAP = [
        [1,  6,  'Starter', 'Beginner',           'مبتدئ'],
        [7,  20, 'A1',      'Elementary',          'ابتدائي'],
        [21, 34, 'A2',      'Pre-Intermediate',    'ما قبل المتوسط'],
        [35, 48, 'B1',      'Intermediate',        'متوسط'],
        [49, 62, 'B2',      'Upper Intermediate',  'فوق المتوسط'],
        [63, 70, 'C1',      'Advanced',            'متقدم'],
    ];

    /**
     * Grade the student's answers against the test answer key.
     *
     * @param  PlacementTest  $test
     * @param  array          $answers  { "question_id" => "a|b|c|d" }
     * @return array { score, total_questions, percentage, estimated_level, estimated_level_code, estimated_level_en, estimated_level_ar }
     */
    public function grade(PlacementTest $test, array $answers): array
    {
        if (! $test->relationLoaded('questions')) {
            $test->load('questions');
        }

        $answerKey = $test->questions->pluck('correct_option', 'id');
        $total     = $test->total_questions;

        $score = 0;
        foreach ($answers as $questionId => $chosen) {
            $correct = $answerKey->get((int) $questionId);
            if ($correct !== null && strtolower($chosen) === strtolower($correct)) {
                $score++;
            }
        }

        $percentage = $total > 0 ? round(($score / $total) * 100, 2) : 0.0;
        $details    = $this->mapLevelDetails($score);

        return [
            'score'                => $score,
            'total_questions'      => $total,
            'percentage'           => $percentage,
            'estimated_level'      => $details['code'],
            'estimated_level_code' => $details['code'],
            'estimated_level_en'   => $details['en'],
            'estimated_level_ar'   => $details['ar'],
        ];
    }

    /** Returns the CEFR code for a raw score (e.g. 'B1'). */
    public function mapLevel(int $score): string
    {
        return $this->mapLevelDetails($score)['code'];
    }

    /** Returns the full level detail array for a raw score. */
    public function mapLevelDetails(int $score): array
    {
        foreach (self::LEVEL_MAP as [$min, $max, $code, $en, $ar]) {
            if ($score >= $min && $score <= $max) {
                return ['code' => $code, 'en' => $en, 'ar' => $ar];
            }
        }

        $fallback = $score <= 0
            ? ['code' => 'Starter', 'en' => 'Beginner',  'ar' => 'مبتدئ']
            : ['code' => 'C1',      'en' => 'Advanced',  'ar' => 'متقدم'];

        return $fallback;
    }

    /**
     * Look up level details from a stored code or legacy English label.
     * Handles both new CEFR codes ('B1') and old labels ('Intermediate').
     */
    public static function levelFromCode(string $codeOrLabel): array
    {
        foreach (self::LEVEL_MAP as [$min, $max, $code, $en, $ar]) {
            if ($code === $codeOrLabel || $en === $codeOrLabel) {
                return ['code' => $code, 'en' => $en, 'ar' => $ar];
            }
        }

        return ['code' => $codeOrLabel, 'en' => $codeOrLabel, 'ar' => null];
    }
}
