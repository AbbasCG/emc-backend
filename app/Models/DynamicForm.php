<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class DynamicForm extends Model
{
    // Soft-deleted: removing a form must never cascade-destroy its submissions and answers.
    use SoftDeletes;

    public const STATUSES = ['draft', 'active', 'closed', 'archived'];

    /** Who may open the form: only its owner and collaborators (and platform admins), or everyone its scope allows. */
    public const VISIBILITIES = ['private', 'organization'];

    /** Who may read the responses: only the owner, collaborators granted it and platform admins, or whoever can open the form. */
    public const RESPONSE_VISIBILITIES = ['restricted', 'form_viewers'];

    protected $fillable = [
        'title', 'slug', 'description', 'type', 'department_id',
        'related_type', 'related_id', 'status', 'created_by',
        'owner_id', 'last_edited_by', 'published_by', 'published_at', 'published_version_id',
        'visibility', 'responses_visibility',
        'opens_at', 'closes_at', 'max_submissions', 'per_respondent_limit', 'allow_anonymous',
        'invitation_only', 'requires_share_link', 'flow',
    ];

    /** Who may answer the form: anyone, any signed-in user, or only invited signed-in users. */
    public const ACCESS_PUBLIC = 'public';

    public const ACCESS_AUTHENTICATED = 'authenticated';

    public const ACCESS_INVITED = 'invited';

    public const ACCESS_MODES = [self::ACCESS_PUBLIC, self::ACCESS_AUTHENTICATED, self::ACCESS_INVITED];

    /**
     * In-memory defaults equal the column defaults: a form object that was not given these values behaves as every
     * form did before Phase 1 (organisation scope, responses visible to whoever can open it). The application creates
     * NEW forms with the stricter private / restricted values explicitly.
     */
    protected $attributes = [
        'visibility'           => 'organization',
        'responses_visibility' => 'form_viewers',
        'allow_anonymous'      => true,
    ];

    protected $casts = [
        'published_at'    => 'datetime',
        'opens_at'        => 'datetime',
        'closes_at'       => 'datetime',
        'allow_anonymous' => 'boolean',
        'draft_revision'  => 'integer',
        'invitation_only' => 'boolean',
        'requires_share_link' => 'boolean',
        'flow'            => 'array',
        'automation'      => 'array',
    ];

    /** The owner; for a form with no owner recorded, its creator. Ownership never silently disappears. */
    public function effectiveOwnerId(): ?int
    {
        $id = $this->owner_id ?? $this->created_by;

        return $id === null ? null : (int) $id;
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function lastEditor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_edited_by');
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function publishedVersion(): BelongsTo
    {
        return $this->belongsTo(FormVersion::class, 'published_version_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(FormVersion::class, 'form_id')->orderByDesc('version_number');
    }

    public function collaborators(): HasMany
    {
        return $this->hasMany(FormCollaborator::class, 'form_id');
    }

    public function shareLinks(): HasMany
    {
        return $this->hasMany(FormShareLink::class, 'form_id')->orderByDesc('id');
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(FormInvitation::class, 'form_id')->orderByDesc('id');
    }

    /**
     * The access mode, derived from the two stored flags so they can never disagree: an invitation-only form is `invited`
     * whatever else is set; otherwise `allow_anonymous` decides between `public` and `authenticated`.
     */
    public function accessMode(): string
    {
        if ($this->invitation_only) {
            return self::ACCESS_INVITED;
        }

        return $this->allow_anonymous ? self::ACCESS_PUBLIC : self::ACCESS_AUTHENTICATED;
    }

    /** The requested link when it is free; otherwise the same link with a short random tail. Existing links never change. */
    public static function freeSlug(?string $requested, string $title): string
    {
        $base = $requested !== null && $requested !== '' ? $requested : (Str::slug($title) ?: 'form');

        if (! self::withTrashed()->where('slug', $base)->exists()) {
            return $base;
        }

        do {
            $candidate = Str::limit($base, 140, '').'-'.Str::lower(Str::random(5));
        } while (self::withTrashed()->where('slug', $candidate)->exists());

        return $candidate;
    }

    public function questions(): HasMany
    {
        return $this->hasMany(FormQuestion::class, 'form_id')->orderBy('sort_order');
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(FormSubmission::class, 'form_id');
    }
}
