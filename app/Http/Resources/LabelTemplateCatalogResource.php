<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LabelTemplateCatalogResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $definition = $this->publishedVersion->definition->toArray();

        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'version' => [
                'id' => $this->publishedVersion->id,
                'revision_code' => $this->publishedVersion->revision_code,
            ],
            'stock' => [
                'id' => $this->labelStock->id,
                'name' => $this->labelStock->name,
                'width_mm' => (float) $this->labelStock->width,
                'height_mm' => (float) $this->labelStock->height,
            ],
            'fields' => $definition['fields'],
        ];
    }
}
