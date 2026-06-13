<?php

namespace App\Http\Resources;

use App\Models\Instructor;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/** Public-safe instructor payload for /api/instructors */
class PublicInstructorResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Instructor $profile */
        $profile = $this->resource;
        $user    = $profile->relationLoaded('user') ? $profile->user : null;

        $avatarPath = $user?->avatar ?? $profile->image;
        $avatarUrl  = self::resolveAvatarUrl($avatarPath);

        $specialization = $profile->title
            ?: ($user?->department ?: null);

        $slug = Instructor::publicSlug($profile->name, $profile->id);

        $courses = $profile->relationLoaded('courses')
            ? $profile->courses->map(fn ($c) => [
                'id'    => $c->id,
                'slug'  => $c->slug,
                'title' => $c->title,
            ])->values()->all()
            : [];

        $learningPaths = $profile->relationLoaded('learningPaths')
            ? $profile->learningPaths->map(fn ($p) => [
                'id'    => $p->id,
                'slug'  => $p->slug,
                'title' => $p->title,
            ])->values()->all()
            : [];

        return [
            'id'                    => $profile->id,
            'slug'                  => $slug,
            'name'                  => $user?->name ?? $profile->name,
            'title'                 => $profile->title,
            'bio'                   => $profile->bio,
            'avatar_url'            => $avatarUrl,
            'image_url'             => $avatarUrl,
            'specialization'        => $specialization,
            'expertise'             => $specialization,
            'courses_count'         => (int) ($profile->courses_count ?? count($courses)),
            'learning_paths_count'  => (int) ($profile->learning_paths_count ?? count($learningPaths)),
            'workshops_count'       => (int) ($profile->workshops_count ?? 0),
            'courses'               => $courses,
            'learning_paths'        => $learningPaths,
        ];
    }

    public static function resolveAvatarUrl(?string $avatarPath): ?string
    {
        if (! $avatarPath) {
            return null;
        }

        if (str_starts_with($avatarPath, 'http://') || str_starts_with($avatarPath, 'https://')) {
            return $avatarPath;
        }

        return Storage::disk('public')->url(ltrim($avatarPath, '/'));
    }
}
