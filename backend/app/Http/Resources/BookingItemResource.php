<?php

namespace App\Http\Resources;

use App\Models\BookingItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin BookingItem
 */
class BookingItemResource extends JsonResource
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
            'service_id' => $this->service_id,
            'service_name' => $this->whenLoaded('service', fn () => $this->service->name),
            'qty' => $this->qty,
            'est_weight_kg' => $this->est_weight_kg,
            'final_weight_kg' => $this->final_weight_kg,
            'rate' => $this->rate,
            'subtotal' => $this->subtotal,
        ];
    }
}
