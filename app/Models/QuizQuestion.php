<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuizQuestion extends Model
{
    protected $fillable = [
        'quiz_id', 'question', 'type', 'options', 'correct_answer', 'points', 'sort_order',
    ];

    protected $casts = [
        'options'        => 'array',
        'correct_answer' => 'array',
    ];

    // Never expose correct answers to students
    protected $hidden = ['correct_answer'];

    public function quiz(): BelongsTo
    {
        return $this->belongsTo(Quiz::class);
    }

    public function makeVisibleForAdmin(): static
    {
        return $this->makeVisible('correct_answer');
    }
}
