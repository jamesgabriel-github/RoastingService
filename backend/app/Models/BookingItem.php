<?php

namespace App\Models;

use Database\Factories\BookingItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'booking_id',
    'service_id',
    'qty',
    'est_weight_kg',
    'final_weight_kg',
    'rate',
    'subtotal',
    'status',
    'approved_at',
    'approved_by',
    'confirmed_at',
    'confirmed_by',
    'weighed_at',
    'cooking_started_at',
    'est_ready_at',
    'completed_at',
    'reject_reason',
])]
class BookingItem extends Model
{
    /** @use HasFactory<BookingItemFactory> */
    use HasFactory;

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'qty' => 'integer',
            'est_weight_kg' => 'decimal:2',
            'final_weight_kg' => 'decimal:2',
            'rate' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'approved_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'weighed_at' => 'datetime',
            'cooking_started_at' => 'datetime',
            'est_ready_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Booking, $this>
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /**
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
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
     * @return HasMany<BookingStatusLog, $this>
     */
    public function statusLogs(): HasMany
    {
        return $this->hasMany(BookingStatusLog::class, 'booking_item_id');
    }

    /**
     * @return HasOne<BookingStatusLog, $this>
     */
    public function latestStatusLog(): HasOne
    {
        return $this->hasOne(BookingStatusLog::class, 'booking_item_id')->latestOfMany();
    }
}
