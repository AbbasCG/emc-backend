<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FormAnswerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'question_id'    => $this->question_id,
            'question_label' => $this->whenLoaded('question', fn () => $this->question->label),
            'value'          => $this->value,
            'file_path'      => $this->file_path,
        ];
    }
}
