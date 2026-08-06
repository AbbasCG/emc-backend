<?php

namespace App\Services;

use App\Models\Course;
use App\Models\Department;
use App\Models\Instructor;
use App\Models\KnowledgeArticle;
use App\Models\Meeting;
use App\Models\Partner;
use App\Models\Task;
use App\Models\Track;
use App\Models\Workshop;

class SearchService
{
    public function search(string $query, ?string $userRole = null): array
    {
        $q = '%' . $query . '%';

        $courses = Course::where('title', 'like', $q)
            ->orWhere('description', 'like', $q)
            ->orWhere('slug', 'like', $q)
            ->select('id', 'title', 'slug', 'type', 'status')
            ->limit(10)
            ->get();

        $workshops = Workshop::where('title', 'like', $q)
            ->orWhere('description', 'like', $q)
            ->select('id', 'title', 'status')
            ->limit(10)
            ->get();

        $tracks = Track::where('name', 'like', $q)
            ->orWhere('description', 'like', $q)
            ->select('id', 'name', 'slug', 'status')
            ->limit(10)
            ->get();

        $instructors = Instructor::where('name', 'like', $q)
            ->orWhere('bio', 'like', $q)
            ->select('id', 'name', 'specialization')
            ->limit(10)
            ->get();

        $result = compact('courses', 'workshops', 'tracks', 'instructors');

        // Internal-only results — only for admin/teacher roles
        if (in_array($userRole, ['admin', 'teacher'])) {
            $departments = Department::where('name', 'like', $q)
                ->orWhere('description', 'like', $q)
                ->select('id', 'name', 'slug', 'status')
                ->limit(10)
                ->get();

            $tasks = Task::where('title', 'like', $q)
                ->orWhere('description', 'like', $q)
                ->select('id', 'title', 'status', 'priority', 'due_date')
                ->limit(10)
                ->get();

            $meetings = Meeting::where('title', 'like', $q)
                ->select('id', 'title', 'type', 'status', 'meeting_date')
                ->limit(10)
                ->get();

            $partners = Partner::where('name', 'like', $q)
                ->select('id', 'name', 'type', 'status')
                ->limit(10)
                ->get();

            $knowledge = KnowledgeArticle::where('title', 'like', $q)
                ->orWhere('excerpt', 'like', $q)
                ->where('status', 'published')
                ->select('id', 'title', 'slug', 'type', 'visibility')
                ->limit(10)
                ->get();

            $result = array_merge($result, compact('departments', 'tasks', 'meetings', 'partners', 'knowledge'));
        }

        return $result;
    }
}
