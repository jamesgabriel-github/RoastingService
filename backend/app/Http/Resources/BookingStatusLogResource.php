<?php

namespace App\Http\Resources;

use App\Models\BookingStatusLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin BookingStatusLog
 */
class BookingStatusLogResource extends JsonResource
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
            'status' => $this->status,
            'changed_by_name' => $this->whenLoaded('changer', fn () => $this->changer?->name),
            'remarks' => $this->remarks,
            'created_at' => $this->created_at,
        ];
    }
}
