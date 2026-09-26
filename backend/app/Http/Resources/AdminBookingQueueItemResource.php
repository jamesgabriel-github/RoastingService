<?php

namespace App\Http\Resources;

use App\Models\BookingItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin BookingItem
 */
class AdminBookingQueueItemResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $booking = $this->booking;

        return [
            'id' => $this->id,
            'booking_id' => $this->booking_id,
            'code' => $booking->code,
            'is_order' => $booking->is_order,
            'status' => $this->status,
            'service_name' => $this->whenLoaded('service', fn () => $this->service->name),
            'qty' => $this->qty,
            'est_weight_kg' => $this->est_weight_kg,
            'final_weight_kg' => $this->final_weight_kg,
            'subtotal' => $this->subtotal,
            'fulfillment' => $booking->fulfillment,
            'customer_name' => $booking->customer
                ? trim("{$booking->customer->first_name} {$booking->customer->last_name}")
                : $booking->guest_name,
            'customer_phone' => $booking->customer?->phone ?? $booking->guest_phone,
            'waiting_minutes' => (int) now()->diffInMinutes($this->latestStatusLog?->created_at ?? $booking->created_at, absolute: true),
        ];
    }
}
