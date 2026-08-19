<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CourseAssessmentSetting extends Model
{
    public const MODE_EQUAL  = 'equal';
    public const MODE_CUSTOM = 'custom';

    protected $fillable = [
        'course_id', 'quiz_component_weight', 'final_exam_weight', 'quiz_weighting_mode', 'gates_certificate',
    ];

    protected $casts = [
        'quiz_component_weight' => 'decimal:2',
        'final_exam_weight'     => 'decimal:2',
        'gates_certificate'     => 'boolean',
    ];

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /** Default settings for a course that hasn't configured quizzes: 100% final exam, preserving current behavior. */
    public static function defaults(int $courseId): array
    {
        return [
            'course_id' => $courseId,
            'quiz_component_weight' => 0,
            'final_exam_weight' => 100,
            'quiz_weighting_mode' => self::MODE_EQUAL,
            'gates_certificate' => false,
        ];
    }

    public static function weightsAreValid(float $quizWeight, float $examWeight): bool
    {
        return abs(($quizWeight + $examWeight) - 100.0) < 0.01;
    }
}
