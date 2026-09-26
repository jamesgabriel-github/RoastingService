<?php

namespace App\Http\Resources;

use App\Models\Booking;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Booking
 */
class BookingResource extends JsonResource
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
            'is_order' => $this->is_order,
            'status' => $this->status,
            'fulfillment' => $this->fulfillment,
            'delivery_address' => $this->delivery_address,
            'shipping_fee' => $this->shipping_fee,
            'estimated_total' => $this->estimated_total,
            'total_amount' => $this->total_amount,
            'preferred_dropoff_at' => $this->preferred_dropoff_at,
            'preferred_pickup_at' => $this->preferred_pickup_at,
            'notes' => $this->notes,
            'items' => BookingItemResource::collection($this->whenLoaded('items')),
            'status_logs' => BookingStatusLogResource::collection($this->whenLoaded('statusLogs')),
            'created_at' => $this->created_at,
        ];
    }
}
