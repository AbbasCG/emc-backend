<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaterialAuditLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'material_id',
        'user_id',
        'action',
        'ip_address',
        'user_agent',
        'metadata',
    ];

    protected $casts = [
        'metadata'   => 'array',
        'created_at' => 'datetime',
    ];

    public function material(): BelongsTo
    {
        return $this->belongsTo(CourseMaterial::class, 'material_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Quickly record any material action. */
    public static function record(
        CourseMaterial $material,
        string $action,
        ?int $userId = null,
        ?string $ip = null,
        ?string $ua = null,
        array $metadata = [],
    ): self {
        return self::create([
            'material_id' => $material->id,
            'user_id'     => $userId,
            'action'      => $action,
            'ip_address'  => $ip,
            'user_agent'  => $ua ? substr($ua, 0, 500) : null,
            'metadata'    => array_merge([
                'material_title'     => $material->title,
                'original_filename'  => $material->original_filename,
            ], $metadata),
        ]);
    }
}
