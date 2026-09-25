<?php

namespace App\Http\Resources;

use App\Models\Service;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Service
 */
class PublicServiceResource extends JsonResource
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
            'name' => $this->name,
            'description' => $this->description,
            'roasting_rate_per_kg' => $this->roasting_rate_per_kg,
            'shop_price' => $this->shop_price,
            'est_minutes' => $this->est_minutes,
            'allow_customer_supplied' => $this->allow_customer_supplied,
            'allow_shop_supplied' => $this->allow_shop_supplied,
            'in_stock' => $this->allow_shop_supplied ? $this->stock_qty > 0 : null,
        ];
    }
}
