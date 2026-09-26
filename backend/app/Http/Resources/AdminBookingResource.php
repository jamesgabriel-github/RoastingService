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
        $paidAmount = $this->relationLoaded('payments')
            ? number_format((float) $this->payments->where('status', 'paid')->sum('amount'), 2, '.', '')
            : '0.00';

        return [
            'id' => $this->id,
            'code' => $this->code,
            'is_order' => $this->is_order,
            'fulfillment' => $this->fulfillment,
            'delivery_address' => $this->delivery_address,
            'customer_name' => $this->customer
                ? trim("{$this->customer->first_name} {$this->customer->last_name}")
                : $this->guest_name,
            'customer_phone' => $this->customer?->phone ?? $this->guest_phone,
            'estimated_total' => $this->estimated_total,
            'total_amount' => $this->total_amount,
            'paid_amount' => $paidAmount,
            'balance' => $this->total_amount === null ? null : number_format((float) $this->total_amount - (float) $paidAmount, 2, '.', ''),
            'preferred_dropoff_at' => $this->preferred_dropoff_at,
            'preferred_pickup_at' => $this->preferred_pickup_at,
            'dropoff_at' => $this->dropoff_at,
            'notes' => $this->notes,
            'items' => AdminBookingItemResource::collection($this->whenLoaded('items')),
            'status_logs' => BookingStatusLogResource::collection($this->whenLoaded('statusLogs')),
            'created_at' => $this->created_at,
        ];
    }
}
