<?php

namespace App\Http\Resources;

use App\Models\Printer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Printer */
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
            'online' => app()->environment(['local', 'testing'])
                || ($this->printBridge->last_seen_at?->greaterThanOrEqualTo(now()->subMinutes(2)) ?? false),
        ];
    }
}
