<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlacementTestQuestion extends Model
{
    protected $fillable = [
        'placement_test_id',
        'question_text',
        'option_a',
        'option_b',
        'option_c',
        'option_d',
        'correct_option',
        'order',
        'level_band',
    ];

    public function placementTest(): BelongsTo
    {
        return $this->belongsTo(PlacementTest::class);
    }

    /** All four options as an associative array — correct_option excluded from API responses. */
    public function optionsArray(): array
    {
        return [
            'a' => $this->option_a,
            'b' => $this->option_b,
            'c' => $this->option_c,
            'd' => $this->option_d,
        ];
    }
}
