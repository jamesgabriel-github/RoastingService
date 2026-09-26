<?php

namespace App\Models;

use Database\Factories\BookingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'code',
    'customer_id',
    'guest_name',
    'guest_phone',
    'is_order',
    'fulfillment',
    'delivery_address',
    'shipping_fee',
    'preferred_dropoff_at',
    'preferred_pickup_at',
    'dropoff_at',
    'estimated_total',
    'total_amount',
    'notes',
])]
class Booking extends Model
{
    /** @use HasFactory<BookingFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_order' => 'boolean',
            'shipping_fee' => 'decimal:2',
            'estimated_total' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'preferred_dropoff_at' => 'datetime',
            'preferred_pickup_at' => 'datetime',
            'dropoff_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    /**
     * @return HasMany<BookingItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(BookingItem::class);
    }

    /**
     * @return HasMany<BookingStatusLog, $this>
     */
    public function statusLogs(): HasMany
    {
        return $this->hasMany(BookingStatusLog::class);
    }

    /**
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * The status shared by every one of this booking's items, or `null` once
     * they've diverged (only possible from Cooking on). Requires `items` to
     * already be loaded.
     */
    public function commonStatus(): ?string
    {
        $statuses = $this->items->pluck('status')->unique();

        return $statuses->count() === 1 ? $statuses->first() : null;
    }
}
