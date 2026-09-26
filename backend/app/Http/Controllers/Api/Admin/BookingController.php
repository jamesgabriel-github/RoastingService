<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ApproveBookingRequest;
use App\Http\Requests\Admin\RejectBookingRequest;
use App\Http\Requests\Admin\WeighInBookingRequest;
use App\Http\Resources\AdminBookingQueueItemResource;
use App\Http\Resources\AdminBookingResource;
use App\Models\Booking;
use App\Models\BookingItem;
use App\Models\BookingStatusLog;
use App\Models\InventoryLog;
use App\Models\Service;
use App\Services\Booking\BookingStatusEngine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BookingController extends Controller
{
    /**
     * Raw statuses grouped into the six admin queue tabs. A booking's items
     * never diverge before Cooking, so `draft`/`pending` always hold whole
     * bookings; `cooking` on carries items that may have moved independently.
     *
     * @var array<string, list<string>>
     */
    public const GROUPS = [
        'draft' => ['pending_review', 'pending_confirmation', 'approved'],
        'pending' => ['confirmed'],
        'cooking' => ['cooking'],
        'ready' => ['ready', 'out_for_delivery'],
        'completed' => ['completed'],
        'cancelled' => ['rejected', 'cancelled', 'no_show'],
    ];

    /**
     * @return array<string, int>
     */
    public function counts(): array
    {
        $counts = BookingItem::whereIn('status', collect(self::GROUPS)->flatten()->all())
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return collect(self::GROUPS)
            ->map(fn (array $statuses) => (int) collect($statuses)->sum(fn (string $status) => $counts[$status] ?? 0))
            ->all();
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'group' => ['required', 'string', 'in:'.implode(',', array_keys(self::GROUPS))],
            'search' => ['nullable', 'string', 'max:255'],
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $date = $validated['date'] ?? now()->toDateString();

        $query = BookingItem::whereIn('status', self::GROUPS[$validated['group']])
            ->whereHas('booking', fn ($query) => $query->whereDate('preferred_pickup_at', $date))
            ->with(['service', 'booking.customer', 'latestStatusLog']);

        if (! empty($validated['search'])) {
            $like = '%'.$validated['search'].'%';
            $query->whereHas('booking', function ($query) use ($like) {
                $query->where('code', 'ilike', $like)
                    ->orWhere('guest_name', 'ilike', $like)
                    ->orWhere('guest_phone', 'ilike', $like)
                    ->orWhereHas('customer', function ($query) use ($like) {
                        $query->where('phone', 'ilike', $like)
                            ->orWhereRaw("concat_ws(' ', first_name, last_name) ilike ?", [$like]);
                    });
            });
        }

        $latestLog = BookingStatusLog::select('created_at')
            ->whereColumn('booking_item_id', 'booking_items.id')
            ->latest('id')
            ->limit(1);

        $query->orderBy($latestLog)->orderBy('booking_items.id');

        return AdminBookingQueueItemResource::collection($query->paginate());
    }

    public function show(int $id): AdminBookingResource
    {
        $booking = Booking::with(self::detailEagerLoads())->findOrFail($id);

        return new AdminBookingResource($booking);
    }

    public function approve(ApproveBookingRequest $request, BookingStatusEngine $statusEngine, int $id): AdminBookingResource
    {
        $booking = DB::transaction(function () use ($request, $statusEngine, $id) {
            $booking = Booking::with('items')->lockForUpdate()->findOrFail($id);

            $this->guardTransition($booking, $statusEngine, 'approved', 'approved');

            $booking->dropoff_at = $request->validated('dropoff_at');
            $booking->save();

            $now = now();

            foreach ($booking->items as $item) {
                $item->approved_at = $now;
                $item->approved_by = $request->user()->id;
                $item->save();

                $statusEngine->transition($item, 'approved', $request->user()->id);
            }

            return $booking;
        });

        return new AdminBookingResource($booking->load(self::detailEagerLoads()));
    }

    public function reject(RejectBookingRequest $request, BookingStatusEngine $statusEngine, int $id): AdminBookingResource
    {
        $booking = DB::transaction(function () use ($request, $statusEngine, $id) {
            $booking = Booking::with('items')->lockForUpdate()->findOrFail($id);

            $this->guardTransition($booking, $statusEngine, 'rejected', 'rejected');

            $reason = $request->validated('reason');

            foreach ($booking->items as $item) {
                $item->reject_reason = $reason;
                $item->save();

                $statusEngine->transition($item, 'rejected', $request->user()->id, $reason);
            }

            return $booking;
        });

        return new AdminBookingResource($booking->load(self::detailEagerLoads()));
    }

    public function weighIn(WeighInBookingRequest $request, BookingStatusEngine $statusEngine, int $id): AdminBookingResource
    {
        $booking = DB::transaction(function () use ($request, $statusEngine, $id) {
            $booking = Booking::with('items')->lockForUpdate()->findOrFail($id);

            $this->guardTransition($booking, $statusEngine, 'confirmed', 'weighed in');

            $submitted = collect($request->validated('items'))->keyBy(fn (array $item) => (int) $item['id']);
            $bookingItemIds = $booking->items->pluck('id');

            if ($submitted->keys()->sort()->values()->all() !== $bookingItemIds->sort()->values()->all()) {
                throw ValidationException::withMessages([
                    'items' => 'Every item on this booking needs a final weight.',
                ]);
            }

            $totalAmount = 0.0;
            $now = now();

            foreach ($booking->items as $item) {
                $finalWeightKg = (float) $submitted[$item->id]['final_weight_kg'];
                $subtotal = round((float) $item->rate * $finalWeightKg, 2);

                $item->final_weight_kg = $finalWeightKg;
                $item->subtotal = $subtotal;
                $item->weighed_at = $now;
                $item->confirmed_at = $now;
                $item->confirmed_by = $request->user()->id;
                $item->save();

                $totalAmount += $subtotal;

                $statusEngine->transition($item, 'confirmed', $request->user()->id);
            }

            $booking->total_amount = round($totalAmount, 2);
            $booking->save();

            return $booking;
        });

        return new AdminBookingResource($booking->load(self::detailEagerLoads()));
    }

    public function confirmOrder(Request $request, BookingStatusEngine $statusEngine, int $id): AdminBookingResource
    {
        $booking = DB::transaction(function () use ($request, $statusEngine, $id) {
            $booking = Booking::with('items')->lockForUpdate()->findOrFail($id);

            $this->guardOrderTransition($booking, $statusEngine, 'confirmed', 'confirmed');

            $now = now();

            foreach ($booking->items as $item) {
                $item->confirmed_at = $now;
                $item->confirmed_by = $request->user()->id;
                $item->save();

                $statusEngine->transition($item, 'confirmed', $request->user()->id);
            }

            return $booking;
        });

        return new AdminBookingResource($booking->load(self::detailEagerLoads()));
    }

    public function rejectOrder(RejectBookingRequest $request, BookingStatusEngine $statusEngine, int $id): AdminBookingResource
    {
        $booking = DB::transaction(function () use ($request, $statusEngine, $id) {
            $booking = Booking::with('items')->lockForUpdate()->findOrFail($id);

            $this->guardOrderTransition($booking, $statusEngine, 'rejected', 'rejected');

            $reason = $request->validated('reason');

            foreach ($booking->items as $item) {
                Service::whereKey($item->service_id)->increment('stock_qty', $item->qty);

                InventoryLog::create([
                    'service_id' => $item->service_id,
                    'change_qty' => $item->qty,
                    'reason' => 'release',
                    'booking_id' => $booking->id,
                    'created_by' => $request->user()->id,
                ]);

                $item->reject_reason = $reason;
                $item->save();

                $statusEngine->transition($item, 'rejected', $request->user()->id, $reason);
            }

            return $booking;
        });

        return new AdminBookingResource($booking->load(self::detailEagerLoads()));
    }

    public function noShow(Request $request, BookingStatusEngine $statusEngine, int $id): AdminBookingResource
    {
        $validated = $request->validate([
            'remarks' => ['nullable', 'string', 'max:255'],
        ]);

        $booking = DB::transaction(function () use ($request, $statusEngine, $id, $validated) {
            $booking = Booking::with('items')->lockForUpdate()->findOrFail($id);

            $this->guardTransition($booking, $statusEngine, 'no_show', 'marked no-show');

            foreach ($booking->items as $item) {
                $statusEngine->transition($item, 'no_show', $request->user()->id, $validated['remarks'] ?? null);
            }

            return $booking;
        });

        return new AdminBookingResource($booking->load(self::detailEagerLoads()));
    }

    public function cancel(Request $request, BookingStatusEngine $statusEngine, int $id): AdminBookingResource
    {
        $validated = $request->validate([
            'remarks' => ['nullable', 'string', 'max:255'],
        ]);

        $booking = DB::transaction(function () use ($request, $statusEngine, $id, $validated) {
            $booking = Booking::with('items')->lockForUpdate()->findOrFail($id);

            $this->guardAnyTransition($booking, $statusEngine, 'cancelled', 'cancelled');

            if ($booking->is_order) {
                foreach ($booking->items->sortBy('service_id') as $item) {
                    Service::where('id', $item->service_id)->increment('stock_qty', $item->qty);

                    InventoryLog::create([
                        'service_id' => $item->service_id,
                        'change_qty' => $item->qty,
                        'reason' => 'release',
                        'booking_id' => $booking->id,
                        'created_by' => $request->user()->id,
                    ]);
                }
            }

            foreach ($booking->items as $item) {
                $statusEngine->transition($item, 'cancelled', $request->user()->id, $validated['remarks'] ?? null);
            }

            return $booking;
        });

        return new AdminBookingResource($booking->load(self::detailEagerLoads()));
    }

    /**
     * Approve, reject, and weigh-in are only for bring-your-own (non-order)
     * bookings - a shop order reaches `rejected`/`confirmed` through feature
     * 11's own flow instead, which also releases its reserved stock on
     * rejection. `BookingStatusEngine::isAllowed` alone does not scope to the
     * order flag, since an order can reach both statuses too, so this checks
     * both. A booking's items all share one status before Cooking, so the
     * booking's common status stands in for "the booking's status".
     */
    private function guardTransition(Booking $booking, BookingStatusEngine $statusEngine, string $to, string $action): void
    {
        if (
            $booking->is_order
            || ! $statusEngine->isAllowed($booking->is_order, $booking->commonStatus(), $to)
        ) {
            throw ValidationException::withMessages([
                'status' => "This booking cannot be {$action} right now.",
            ]);
        }
    }

    /**
     * Confirm and reject-order are only for shop orders - the bring-your-own
     * counterpart is `guardTransition` above.
     */
    private function guardOrderTransition(Booking $booking, BookingStatusEngine $statusEngine, string $to, string $action): void
    {
        if (
            ! $booking->is_order
            || ! $statusEngine->isAllowed($booking->is_order, $booking->commonStatus(), $to)
        ) {
            throw ValidationException::withMessages([
                'status' => "This booking cannot be {$action} right now.",
            ]);
        }
    }

    /**
     * For cancel, valid across both order flags.
     */
    private function guardAnyTransition(Booking $booking, BookingStatusEngine $statusEngine, string $to, string $action): void
    {
        if (! $statusEngine->isAllowed($booking->is_order, $booking->commonStatus(), $to)) {
            throw ValidationException::withMessages([
                'status' => "This booking cannot be {$action} right now.",
            ]);
        }
    }

    /**
     * @return array<int|string, string|\Closure>
     */
    public static function detailEagerLoads(): array
    {
        return [
            'items.service',
            'items.approver',
            'items.confirmer',
            'items.latestStatusLog',
            'customer',
            'statusLogs' => fn ($query) => $query->orderBy('id')->with(['changer', 'item.service']),
            'payments',
        ];
    }
}
