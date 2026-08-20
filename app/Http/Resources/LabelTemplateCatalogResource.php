<?php

namespace App\Http\Resources;

use App\Models\LabelTemplate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use LogicException;

/** @mixin LabelTemplate */
class LabelTemplateCatalogResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $publishedVersion = $this->publishedVersion;

        if ($publishedVersion === null) {
            throw new LogicException('A catalog label template must have a published version.');
        }

        $definition = $publishedVersion->definition->toArray();

        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'version' => [
                'id' => $publishedVersion->id,
                'revision_code' => $publishedVersion->revision_code,
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
