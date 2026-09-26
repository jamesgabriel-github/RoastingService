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
            ->selectRaw('bookings.is_order::int as is_order, sum(payments.amount) as aggregate')
            ->groupBy('bookings.is_order')
            ->pluck('aggregate', 'is_order');

        $notOrder = (float) ($totals[0] ?? 0);
        $isOrder = (float) ($totals[1] ?? 0);

        return [
            'not_order' => number_format($notOrder, 2, '.', ''),
            'is_order' => number_format($isOrder, 2, '.', ''),
            'total' => number_format($notOrder + $isOrder, 2, '.', ''),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function bookingsByStatus(): array
    {
        $counts = BookingItem::selectRaw('status, count(*) as aggregate')
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
        return BookingItem::whereIn('status', ['cooking', 'ready'])
            ->with(['booking.customer', 'latestStatusLog'])
            ->get()
            ->map(fn (BookingItem $item) => [
                'id' => $item->id,
                'code' => $item->booking->code,
                'is_order' => $item->booking->is_order,
                'status' => $item->status,
                'customer_name' => $item->booking->customer
                    ? trim("{$item->booking->customer->first_name} {$item->booking->customer->last_name}")
                    : $item->booking->guest_name,
                'fulfillment' => $item->booking->fulfillment,
                'cooking_started_at' => $item->cooking_started_at,
                'est_ready_at' => $item->est_ready_at,
                'waiting_minutes' => (int) now()->diffInMinutes($item->latestStatusLog?->created_at ?? $item->booking->created_at, absolute: true),
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
            ->join('services', 'services.id', '=', 'booking_items.service_id')
            ->where('booking_items.status', 'completed')
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
