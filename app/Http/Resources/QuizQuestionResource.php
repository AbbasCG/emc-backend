<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class QuizQuestionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'question'       => $this->question,
            'type'           => $this->type,
            'options'        => $this->options,
            'points'         => $this->points,
            'sort_order'     => $this->sort_order,
            // correct_answer is hidden by default on the model
        ];
    }
}
