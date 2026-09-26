<?php

namespace App\Models;

use Database\Factories\BookingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'code',
    'customer_id',
    'guest_name',
    'guest_phone',
    'is_order',
    'fulfillment',
    'delivery_address',
    'shipping_fee',
    'status',
    'preferred_dropoff_at',
    'dropoff_at',
    'approved_at',
    'approved_by',
    'confirmed_at',
    'confirmed_by',
    'weighed_at',
    'cooking_started_at',
    'est_ready_at',
    'completed_at',
    'estimated_total',
    'total_amount',
    'notes',
    'reject_reason',
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
            'dropoff_at' => 'datetime',
            'approved_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'weighed_at' => 'datetime',
            'cooking_started_at' => 'datetime',
            'est_ready_at' => 'datetime',
            'completed_at' => 'datetime',
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
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
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
     * @return HasOne<BookingStatusLog, $this>
     */
    public function latestStatusLog(): HasOne
    {
        return $this->hasOne(BookingStatusLog::class)->latestOfMany();
    }
}
