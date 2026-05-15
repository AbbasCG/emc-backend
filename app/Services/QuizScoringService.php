<?php

namespace App\Services;

use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\QuizQuestion;

class QuizScoringService
{
    public function score(Quiz $quiz, array $answers): array
    {
        $questions   = $quiz->questions()->get()->keyBy('id');
        $totalPoints = $questions->sum('points');
        $earned      = 0;
        $details     = [];

        foreach ($answers as $questionId => $givenAnswer) {
            /** @var QuizQuestion|null $question */
            $question = $questions->get($questionId);
            if (! $question) {
                continue;
            }

            $correct = $question->makeVisibleForAdmin()->correct_answer;
            $isCorrect = false;

            if ($question->type === 'multiple_choice' || $question->type === 'true_false') {
                $isCorrect = (string) $givenAnswer === (string) ($correct[0] ?? $correct);
            } elseif ($question->type === 'multi_select') {
                $given   = is_array($givenAnswer) ? $givenAnswer : [$givenAnswer];
                $correct = is_array($correct) ? $correct : [$correct];
                sort($given);
                sort($correct);
                $isCorrect = $given === $correct;
            } elseif ($question->type === 'short_answer') {
                // For short_answer we just mark it as needing manual review
                $isCorrect = false;
            }

            if ($isCorrect) {
                $earned += $question->points;
            }

            $details[$questionId] = ['is_correct' => $isCorrect, 'points_earned' => $isCorrect ? $question->points : 0];
        }

        $percentage = $totalPoints > 0 ? round(($earned / $totalPoints) * 100, 2) : 0;
        $passed     = $quiz->passing_score !== null ? $percentage >= $quiz->passing_score : null;

        return [
            'score'        => $percentage,
            'points_earned'=> $earned,
            'total_points' => $totalPoints,
            'passed'       => $passed,
            'details'      => $details,
        ];
    }
}
