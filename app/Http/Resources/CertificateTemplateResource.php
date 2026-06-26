<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CertificateTemplateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'name'            => $this->name,
            'type'            => $this->type,
            'language'        => $this->language,
            'background_path' => $this->background_path,
            'html_template'   => $this->html_template,
            'css_template'    => $this->css_template,
            'config_json'     => $this->config_json,
            'code_prefix'     => $this->code_prefix,
            'is_active'       => $this->is_active,
            'created_at'      => $this->created_at,
            'updated_at'      => $this->updated_at,

            'created_by'      => $this->whenLoaded('createdBy', fn () => $this->createdBy
                ? ['id' => $this->createdBy->id, 'name' => $this->createdBy->name]
                : null),

            'updated_by'      => $this->whenLoaded('updatedBy', fn () => $this->updatedBy
                ? ['id' => $this->updatedBy->id, 'name' => $this->updatedBy->name]
                : null),
        ];
    }
}
