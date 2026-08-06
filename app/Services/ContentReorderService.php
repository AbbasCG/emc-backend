<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Canonical deterministic batch-reorder logic, shared by admin and
 * instructor module/lesson reorder endpoints so the validation rules
 * (full-list required, no duplicates, no foreign IDs, transactional,
 * sequential positions) exist in exactly one place.
 */
class ContentReorderService
{
    /**
     * @param  Builder  $scopedQuery  Query already scoped to the parent (e.g. CourseModule::where('course_id', $course->id))
     * @param  array<int, int>  $orderedIds  The full list of child IDs in the desired order
     */
    public function reorder(Builder $scopedQuery, array $orderedIds): void
    {
        if ($orderedIds === []) {
            throw ValidationException::withMessages(['ids' => 'يجب إرسال قائمة كاملة بترتيب العناصر.']);
        }

        if (count($orderedIds) !== count(array_unique($orderedIds))) {
            throw ValidationException::withMessages(['ids' => 'لا يمكن تكرار نفس العنصر أكثر من مرة في الترتيب.']);
        }

        $ownedIds = (clone $scopedQuery)->pluck('id')->all();
        sort($ownedIds);
        $sortedRequested = $orderedIds;
        sort($sortedRequested);

        if ($sortedRequested !== $ownedIds) {
            throw ValidationException::withMessages(['ids' => 'يجب أن تتضمن القائمة جميع العناصر التابعة لهذا الأصل فقط، بدون عناصر خارجية أو ناقصة.']);
        }

        DB::transaction(function () use ($scopedQuery, $orderedIds) {
            foreach ($orderedIds as $position => $id) {
                (clone $scopedQuery)->where('id', $id)->update(['sort_order' => $position]);
            }
        });
    }
}
