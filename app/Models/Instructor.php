<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Instructor extends Model
{
    use SoftDeletes;

    public const PUBLIC_ROLES = ['instructor', 'teacher'];

    protected $fillable = [
        'user_id',
        'name',
        'title',
        'bio',
        'image',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function courses(): HasMany
    {
        return $this->hasMany(Course::class);
    }

    public function workshops(): HasMany
    {
        return $this->hasMany(Workshop::class);
    }

    public function learningPaths(): HasMany
    {
        return $this->hasMany(LearningPath::class);
    }

    /** Stable public slug used in /instructors/{slug} URLs. */
    public static function publicSlug(?string $name, int $id): string
    {
        $base = Str::slug($name ?: 'instructor');

        return ($base !== '' ? $base : 'instructor') . '-' . $id;
    }

    public static function idFromPublicSlug(string $slug): ?int
    {
        if (preg_match('/-(\d+)$/', $slug, $matches) !== 1) {
            return null;
        }

        $id = (int) $matches[1];

        return $id > 0 ? $id : null;
    }

    /** Active instructor/teacher users only — for public directory. */
    public function scopePublicDirectory(Builder $query): Builder
    {
        return $query
            ->whereHas('user', function (Builder $q) {
                $q->whereIn('role', self::PUBLIC_ROLES)
                    ->where('is_active', true);
            });
    }

    /**
     * English Institute visibility gate — the single source of truth is the
     * course's own `requires_placement_test` flag, never the instructor's
     * role, department, or course title. Any UI/route that shows English
     * Institute or quiz functionality to an instructor must derive it from
     * this check (or the equivalent per-course flag), not be hardcoded.
     */
    public function hasEnglishCourses(): bool
    {
        return $this->courses()->where('requires_placement_test', true)->exists();
    }
}