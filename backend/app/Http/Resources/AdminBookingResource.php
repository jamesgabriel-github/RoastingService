<?php

namespace App\Http\Resources;

use App\Models\Booking;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Booking
 */
class AdminBookingResource extends JsonResource
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
            'code' => $this->code,
            'source_type' => $this->source_type,
            'status' => $this->status,
            'fulfillment' => $this->fulfillment,
            'delivery_address' => $this->delivery_address,
            'customer_name' => $this->customer
                ? trim("{$this->customer->first_name} {$this->customer->last_name}")
                : $this->guest_name,
            'customer_phone' => $this->customer?->phone ?? $this->guest_phone,
            'estimated_total' => $this->estimated_total,
            'total_amount' => $this->total_amount,
            'preferred_dropoff_at' => $this->preferred_dropoff_at,
            'dropoff_at' => $this->dropoff_at,
            'approved_at' => $this->approved_at,
            'approved_by_name' => $this->whenLoaded('approver', fn () => $this->approver?->name),
            'reject_reason' => $this->reject_reason,
            'confirmed_at' => $this->confirmed_at,
            'confirmed_by_name' => $this->whenLoaded('confirmer', fn () => $this->confirmer?->name),
            'weighed_at' => $this->weighed_at,
            'notes' => $this->notes,
            'waiting_minutes' => (int) now()->diffInMinutes($this->latestStatusLog?->created_at ?? $this->created_at, absolute: true),
            'items' => BookingItemResource::collection($this->whenLoaded('items')),
            'status_logs' => BookingStatusLogResource::collection($this->whenLoaded('statusLogs')),
            'created_at' => $this->created_at,
        ];
    }
}
