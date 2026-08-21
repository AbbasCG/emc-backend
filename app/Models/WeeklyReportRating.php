<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WeeklyReportRating extends Model
{
    protected $fillable = ['weekly_department_report_id', 'user_id', 'scores', 'avg_score', 'note'];

    protected $casts = [
        'scores'    => 'array',
        'avg_score' => 'float',
    ];

    public function report(): BelongsTo
    {
        return $this->belongsTo(WeeklyDepartmentReport::class, 'weekly_department_report_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
