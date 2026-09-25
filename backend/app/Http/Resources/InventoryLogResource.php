<?php

namespace App\Http\Resources;

use App\Models\InventoryLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin InventoryLog
 */
class InventoryLogResource extends JsonResource
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
            'service_name' => $this->service->name,
            'change_qty' => $this->change_qty,
            'reason' => $this->reason,
            'remarks' => $this->remarks,
            'created_by_name' => $this->creator->name,
            'created_at' => $this->created_at,
        ];
    }
}
