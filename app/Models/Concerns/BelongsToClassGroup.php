<?php

namespace App\Models\Concerns;

use App\Models\ClassGroup;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Shared class-group scoping for learning-content models (sessions, materials,
 * assignments, attendance). `class_group_id` is nullable — a null value means
 * "visible to the whole course" (unscoped), preserving all existing behavior;
 * only rows that explicitly set it become isolated to that one class group.
 */
trait BelongsToClassGroup
{
    public function classGroup(): BelongsTo
    {
        return $this->belongsTo(ClassGroup::class);
    }

    /**
     * Content visible to a student in a given class group: unscoped rows
     * (class_group_id null) plus rows scoped to their own class group.
     * Pass null $classGroupId to see only unscoped (course-wide) content.
     */
    public function scopeVisibleToClassGroup(Builder $query, ?int $classGroupId): Builder
    {
        return $query->where(function (Builder $q) use ($classGroupId) {
            $q->whereNull('class_group_id');
            if ($classGroupId !== null) {
                $q->orWhere('class_group_id', $classGroupId);
            }
        });
    }

    /**
     * Same as scopeVisibleToClassGroup, but for a student who may hold
     * memberships across several class groups (one per course). Unscoped
     * rows are always visible; scoped rows are visible only if their
     * class_group_id is one of the student's own memberships.
     */
    public function scopeVisibleToClassGroupIn(Builder $query, iterable $classGroupIds): Builder
    {
        $ids = collect($classGroupIds)->filter()->values();

        return $query->where(function (Builder $q) use ($ids) {
            $q->whereNull('class_group_id');
            if ($ids->isNotEmpty()) {
                $q->orWhereIn('class_group_id', $ids);
            }
        });
    }
}
