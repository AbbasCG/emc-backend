<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class KnowledgeArticleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'title'        => $this->title,
            'slug'         => $this->slug,
            'excerpt'      => $this->excerpt,
            'content'      => $this->when(
                $request->route('slug') || $request->route('article'),
                $this->content
            ),
            'type'         => $this->type,
            'visibility'   => $this->visibility,
            'status'       => $this->status,
            'tags'         => $this->tags,
            'published_at' => $this->published_at,
            'category'     => $this->whenLoaded('category', fn () => [
                'id' => $this->category?->id, 'name' => $this->category?->name,
            ]),
            'author'       => $this->whenLoaded('author', fn () => [
                'id' => $this->author?->id, 'name' => $this->author?->name,
            ]),
            'attachments'  => KnowledgeAttachmentResource::collection($this->whenLoaded('attachments')),
            'created_at'   => $this->created_at,
        ];
    }
}
