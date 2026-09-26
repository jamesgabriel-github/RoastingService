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
            'status' => $this->status,
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
            'dropoff_at' => $this->dropoff_at,
            'approved_at' => $this->approved_at,
            'approved_by_name' => $this->whenLoaded('approver', fn () => $this->approver?->name),
            'reject_reason' => $this->reject_reason,
            'confirmed_at' => $this->confirmed_at,
            'confirmed_by_name' => $this->whenLoaded('confirmer', fn () => $this->confirmer?->name),
            'weighed_at' => $this->weighed_at,
            'cooking_started_at' => $this->cooking_started_at,
            'est_ready_at' => $this->est_ready_at,
            'completed_at' => $this->completed_at,
            'notes' => $this->notes,
            'waiting_minutes' => (int) now()->diffInMinutes($this->latestStatusLog?->created_at ?? $this->created_at, absolute: true),
            'items' => BookingItemResource::collection($this->whenLoaded('items')),
            'status_logs' => BookingStatusLogResource::collection($this->whenLoaded('statusLogs')),
            'created_at' => $this->created_at,
        ];
    }
}
