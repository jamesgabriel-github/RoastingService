<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\BookingItem;
use App\Models\Service;
use Illuminate\Support\Carbon;

class DashboardController extends Controller
{
    /** @var list<string> */
    private const STATUSES = [
        'pending_review',
        'pending_confirmation',
        'approved',
        'confirmed',
        'cooking',
        'ready',
        'out_for_delivery',
        'completed',
        'rejected',
        'no_show',
        'cancelled',
    ];

    /**
     * @return array<string, mixed>
     */
    public function index(): array
    {
        return [
            'sales' => [
                'today' => $this->salesSince(now()->startOfDay()),
                'week' => $this->salesSince(now()->startOfWeek()),
                'month' => $this->salesSince(now()->startOfMonth()),
            ],
            'bookings_by_status' => $this->bookingsByStatus(),
            'active_queue' => $this->activeQueue(),
            'top_items' => $this->topItems(),
            'low_stock' => $this->lowStock(),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function salesSince(Carbon $from): array
    {
        $totals = Booking::query()
            ->join('payments', 'payments.booking_id', '=', 'bookings.id')
            ->where('payments.status', 'paid')
            ->where('payments.paid_at', '>=', $from)
            ->selectRaw('bookings.source_type, sum(payments.amount) as aggregate')
            ->groupBy('bookings.source_type')
            ->pluck('aggregate', 'source_type');

        $customerSupplied = (float) ($totals['customer_supplied'] ?? 0);
        $shopSupplied = (float) ($totals['shop_supplied'] ?? 0);

        return [
            'customer_supplied' => number_format($customerSupplied, 2, '.', ''),
            'shop_supplied' => number_format($shopSupplied, 2, '.', ''),
            'total' => number_format($customerSupplied + $shopSupplied, 2, '.', ''),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function bookingsByStatus(): array
    {
        $counts = Booking::selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return collect(self::STATUSES)
            ->mapWithKeys(fn (string $status) => [$status => (int) ($counts[$status] ?? 0)])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function activeQueue(): array
    {
        return Booking::whereIn('status', ['cooking', 'ready'])
            ->with(['customer', 'latestStatusLog'])
            ->get()
            ->map(fn (Booking $booking) => [
                'id' => $booking->id,
                'code' => $booking->code,
                'source_type' => $booking->source_type,
                'status' => $booking->status,
                'customer_name' => $booking->customer
                    ? trim("{$booking->customer->first_name} {$booking->customer->last_name}")
                    : $booking->guest_name,
                'fulfillment' => $booking->fulfillment,
                'cooking_started_at' => $booking->cooking_started_at,
                'est_ready_at' => $booking->est_ready_at,
                'waiting_minutes' => (int) now()->diffInMinutes($booking->latestStatusLog?->created_at ?? $booking->created_at, absolute: true),
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function topItems(): array
    {
        return BookingItem::query()
            ->join('bookings', 'bookings.id', '=', 'booking_items.booking_id')
            ->join('services', 'services.id', '=', 'booking_items.service_id')
            ->where('bookings.status', 'completed')
            ->selectRaw('booking_items.service_id, services.name, sum(booking_items.qty) as qty_sold')
            ->groupBy('booking_items.service_id', 'services.name')
            ->orderByDesc('qty_sold')
            ->limit(5)
            ->get()
            ->map(fn ($row) => [
                'service_id' => (int) $row->service_id,
                'name' => $row->name,
                'qty_sold' => (int) $row->qty_sold,
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function lowStock(): array
    {
        return Service::where('allow_shop_supplied', true)
            ->where('is_active', true)
            ->whereColumn('stock_qty', '<=', 'low_stock_threshold')
            ->get()
            ->map(fn (Service $service) => [
                'id' => $service->id,
                'name' => $service->name,
                'stock_qty' => $service->stock_qty,
                'low_stock_threshold' => $service->low_stock_threshold,
            ])
            ->all();
    }
}
