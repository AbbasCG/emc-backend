<?php

namespace App\Http\Resources;

use App\Services\StudentModuleProgressService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StudentLearnModuleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        if (! $user) {
            return CourseModuleResource::make($this->resource)->toArray($request);
        }

        return app(StudentModuleProgressService::class)->computeForModule($this->resource, $user);
    }
}
