<?php

namespace App\Http\Resources;

use App\Models\BookingItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin BookingItem
 */
class AdminBookingItemResource extends JsonResource
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
            'status' => $this->status,
            'approved_at' => $this->approved_at,
            'approved_by_name' => $this->whenLoaded('approver', fn () => $this->approver?->name),
            'confirmed_at' => $this->confirmed_at,
            'confirmed_by_name' => $this->whenLoaded('confirmer', fn () => $this->confirmer?->name),
            'weighed_at' => $this->weighed_at,
            'cooking_started_at' => $this->cooking_started_at,
            'est_ready_at' => $this->est_ready_at,
            'completed_at' => $this->completed_at,
            'reject_reason' => $this->reject_reason,
            'waiting_minutes' => (int) now()->diffInMinutes($this->latestStatusLog?->created_at ?? $this->booking?->created_at ?? now(), absolute: true),
        ];
    }
}
