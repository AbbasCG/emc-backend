<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One question. The stored column is `is_required`; `required` is the same value under the name the form builder
 * and the public form use, so neither client has to translate.
 */
class FormQuestionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'label'       => $this->label,
            'type'        => $this->type,
            'options'     => $this->options,
            'is_required' => (bool) $this->is_required,
            'required'    => (bool) $this->is_required,
            'sort_order'  => (int) $this->sort_order,
            'description' => $this->description,
            'placeholder' => $this->placeholder,
            'help_text'   => $this->help_text,
            'config'      => $this->config ?: null,
            'logic'       => $this->logic ?: null,
            // Only where the caller asked for it (withExists): the builder uses it to lock the type of an answered question.
            'has_answers' => $this->when(array_key_exists('answers_exists', $this->getAttributes()), fn () => (bool) $this->getAttribute('answers_exists')),
        ];
    }
}
