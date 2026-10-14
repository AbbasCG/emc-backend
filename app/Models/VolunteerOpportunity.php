<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A volunteer opportunity.
 *
 * Lifecycle: draft -> published -> closed (and published/closed -> draft by unpublishing).
 * `archived` exists in the schema but nothing sets it yet. Only a PUBLISHED opportunity whose
 * deadline has not passed is visible to visitors or accepts applications.
 *
 * `status`, `published_at`, `closed_at` and `published_by` are deliberately not mass-assignable:
 * they change only through VolunteerOpportunityPublishingService, so no request payload can
 * publish, close or backdate an opportunity.
 *
 * `incoming_request_id` is the authoritative link to the HR request the opportunity came from
 * (null for an opportunity created on its own). Applications reach the request through it.
 */
class VolunteerOpportunity extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_CLOSED = 'closed';
    public const STATUS_ARCHIVED = 'archived';

    public const STATUS_LABELS = [
        self::STATUS_DRAFT => 'مسودة',
        self::STATUS_PUBLISHED => 'منشورة',
        self::STATUS_CLOSED => 'مغلقة',
        self::STATUS_ARCHIVED => 'مؤرشفة',
    ];

    public const LOCATION_MODES = ['remote', 'onsite', 'hybrid'];

    public const LOCATION_LABELS = ['remote' => 'عن بُعد', 'onsite' => 'حضوري', 'hybrid' => 'مختلط'];

    protected $fillable = [
        'incoming_request_id', 'department_id', 'title', 'summary', 'responsibilities', 'required_skills', 'preferred_skills',
        'volunteers_needed', 'availability', 'duration', 'location_mode', 'start_date', 'closes_at', 'application_instructions',
        'created_by', 'updated_by',
    ];

    protected $casts = [
        'responsibilities' => 'array',
        'required_skills' => 'array',
        'preferred_skills' => 'array',
        'volunteers_needed' => 'integer',
        'start_date' => 'date',
        'closes_at' => 'date',
        'published_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function incomingRequest(): BelongsTo
    {
        return $this->belongsTo(HrRequest::class, 'incoming_request_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Applications submitted for this opportunity (volunteer_requests.opportunity_id). */
    public function applications(): HasMany
    {
        return $this->hasMany(VolunteerRequest::class, 'opportunity_id');
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT && $this->published_at === null;
    }

    /** Published, and the deadline (the end of the closing day) has not passed. */
    public function acceptsApplications(): bool
    {
        return $this->status === self::STATUS_PUBLISHED
            && $this->published_at !== null
            && ($this->closes_at === null || $this->closes_at->endOfDay()->isFuture());
    }

    /** The single definition of "visible to visitors", used by every public query. */
    public function scopePubliclyVisible(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PUBLISHED)
            ->whereNotNull('published_at')
            ->where(fn (Builder $q) => $q->whereNull('closes_at')->orWhereDate('closes_at', '>=', now()->toDateString()));
    }
}
