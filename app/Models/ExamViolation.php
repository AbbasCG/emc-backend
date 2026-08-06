<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExamViolation extends Model
{
    use HasFactory;

    public const TYPES = [
        'copy', 'paste', 'cut', 'right_click', 'keyboard_shortcut',
        'tab_switch', 'fullscreen_exit', 'window_blur', 'selection_attempt',
    ];

    public const SEVERITIES = ['low', 'medium', 'high'];

    protected $fillable = [
        'user_id',
        'placement_test_attempt_id',
        'question_id',
        'violation_type',
        'severity',
        'meta',
        'ip_address',
        'user_agent',
        'occurred_at',
    ];

    protected $casts = [
        'meta'        => 'array',
        'occurred_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(PlacementTestAttempt::class, 'placement_test_attempt_id');
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(PlacementTestQuestion::class, 'question_id');
    }
}
