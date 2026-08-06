<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class ProductUpdate extends Model
{
    use Auditable, SoftDeletes;

    protected $fillable = [
        'title', 'body', 'category', 'update_type', 'status',
        'target_roles', 'notify_in_app', 'notify_email',
        'published_at', 'scheduled_at',
        'created_by', 'updated_by',
        // CTA
        'cta_label', 'cta_url', 'cta_external',
        // Images
        'image_url', 'image_before_url', 'image_after_url',
        // Maintenance
        'maintenance_start', 'maintenance_end', 'affected_services', 'maintenance_severity',
        // Action / Security
        'due_date', 'assigned_to_roles', 'priority', 'requires_acknowledgement',
        // Bug fix
        'problem_description', 'fix_description', 'affected_users',
        // Misc
        'related_page_url',
    ];

    protected $casts = [
        'target_roles'            => 'array',
        'notify_in_app'           => 'boolean',
        'notify_email'            => 'boolean',
        'cta_external'            => 'boolean',
        'requires_acknowledgement' => 'boolean',
        'affected_services'       => 'array',
        'assigned_to_roles'       => 'array',
        'published_at'            => 'datetime',
        'scheduled_at'            => 'datetime',
        'maintenance_start'       => 'datetime',
        'maintenance_end'         => 'datetime',
        'due_date'                => 'datetime',
    ];

    // ─── Scopes ──────────────────────────────────────────────────────────────

    public function scopePublished($query)
    {
        return $query->where('status', 'published')
                     ->whereNotNull('published_at')
                     ->where('published_at', '<=', now());
    }

    public function scopeForRole($query, string $role)
    {
        return $query->where(function ($q) use ($role) {
            $q->whereNull('target_roles')
              ->orWhereJsonContains('target_roles', $role);
        });
    }

    // ─── Relations ───────────────────────────────────────────────────────────

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function reads(): HasMany
    {
        return $this->hasMany(ProductUpdateRead::class);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    public function hasBeenReadBy(User $user): bool
    {
        return $this->reads()->where('user_id', $user->id)->exists();
    }

    public function markReadBy(User $user): void
    {
        $this->reads()->updateOrCreate(
            ['user_id' => $user->id],
            ['read_at' => now()],
        );
    }
}
