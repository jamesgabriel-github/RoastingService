<?php

namespace App\Models;

use Database\Factories\ServiceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'name',
    'description',
    'roasting_rate_per_kg',
    'shop_price',
    'est_minutes',
    'allow_customer_supplied',
    'allow_shop_supplied',
    'stock_qty',
    'is_active',
])]
class Service extends Model
{
    /** @use HasFactory<ServiceFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'roasting_rate_per_kg' => 'decimal:2',
            'shop_price' => 'decimal:2',
            'est_minutes' => 'integer',
            'allow_customer_supplied' => 'boolean',
            'allow_shop_supplied' => 'boolean',
            'stock_qty' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
