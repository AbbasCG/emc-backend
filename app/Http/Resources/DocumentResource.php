<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'title'       => $this->title,
            'file_name'   => $this->file_name,
            'file_type'   => $this->file_type,
            'file_size'   => $this->file_size,
            'file_path'   => $this->file_path,
            'visibility'  => $this->visibility,
            'tags'        => $this->tags,
            'folder'      => $this->whenLoaded('folder', fn () => [
                'id' => $this->folder?->id, 'name' => $this->folder?->name,
            ]),
            'uploaded_by' => $this->whenLoaded('uploadedBy', fn () => [
                'id' => $this->uploadedBy?->id, 'name' => $this->uploadedBy?->name,
            ]),
            'created_at'  => $this->created_at,
        ];
    }
}
