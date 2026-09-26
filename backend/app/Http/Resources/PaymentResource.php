<?php

namespace App\Http\Resources;

use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Payment
 */
class PaymentResource extends JsonResource
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
            'booking_id' => $this->booking_id,
            'booking_code' => $this->booking->code,
            'customer_name' => $this->booking->customer
                ? trim("{$this->booking->customer->first_name} {$this->booking->customer->last_name}")
                : $this->booking->guest_name,
            'customer_phone' => $this->booking->customer?->phone ?? $this->booking->guest_phone,
            'is_order' => $this->booking->is_order,
            'type' => $this->type,
            'amount' => $this->amount,
            'method' => $this->method,
            'reference_no' => $this->reference_no,
            'status' => $this->status,
            'paid_at' => $this->paid_at,
            'recorded_by_name' => $this->recorder?->name,
            'created_at' => $this->created_at,
        ];
    }
}
