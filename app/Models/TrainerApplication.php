<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class TrainerApplication extends Model
{
    use Auditable;

    protected $fillable = [
        'uuid',
        'status',
        // أولاً: البيانات الشخصية
        'full_name',
        'phone_or_whatsapp',
        'email',
        'linkedin_url',
        // ثانياً: التخصص والخبرة
        'primary_specialty',
        'years_of_experience',
        'has_training_experience',
        // ثالثاً: مجالات التدريب
        'ai_training_fields',
        'other_ai_field',
        'training_program_types',
        'target_audiences',
        // رابعاً: آلية التعاون والتسعير
        'delivery_mode',
        'hourly_rate',
        'currency',
        'currency_other',
        'additional_notes',
        // المراجعة والتدقيق
        'admin_notes',
        'reviewed_by',
        'reviewed_at',
    ];

    protected $casts = [
        'has_training_experience' => 'boolean',
        'ai_training_fields' => 'array',
        'training_program_types' => 'array',
        'target_audiences' => 'array',
        'hourly_rate' => 'decimal:2',
        'reviewed_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
            if (empty($model->status)) {
                $model->status = 'new';
            }
        });
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
