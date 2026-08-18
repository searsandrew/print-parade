<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PrinterCatalogResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'location' => $this->location,
            'language' => $this->language->value,
            'dpi' => $this->dpi,
            'label_stock_id' => $this->label_stock_id,
            'online' => $this->printBridge->last_seen_at?->greaterThanOrEqualTo(now()->subMinutes(2)) ?? false,
        ];
    }
}
