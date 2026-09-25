<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ApproveBookingRequest;
use App\Http\Requests\Admin\RejectBookingRequest;
use App\Http\Requests\Admin\WeighInBookingRequest;
use App\Http\Resources\AdminBookingResource;
use App\Models\Booking;
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
    /** @var list<string> */
    public const QUEUE_STATUSES = [
        'pending_review',
        'pending_confirmation',
        'approved',
        'confirmed',
        'cooking',
        'ready',
        'out_for_delivery',
    ];

    /**
     * @return array<string, int>
     */
    public function counts(): array
    {
        $counts = Booking::whereIn('status', self::QUEUE_STATUSES)
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return collect(self::QUEUE_STATUSES)
            ->mapWithKeys(fn (string $status) => [$status => (int) ($counts[$status] ?? 0)])
            ->all();
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'status' => ['required', 'string', 'in:'.implode(',', self::QUEUE_STATUSES)],
            'search' => ['nullable', 'string', 'max:255'],
        ]);

        $query = Booking::where('status', $validated['status'])->with(['customer', 'latestStatusLog']);

        if (! empty($validated['search'])) {
            $like = '%'.$validated['search'].'%';
            $query->where(function ($query) use ($like) {
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
            ->whereColumn('booking_id', 'bookings.id')
            ->latest('id')
            ->limit(1);

        $query->orderBy($latestLog)->orderBy('bookings.id');

        return AdminBookingResource::collection($query->paginate());
    }

    public function show(int $id): AdminBookingResource
    {
        $booking = Booking::with(self::detailEagerLoads())->findOrFail($id);

        return new AdminBookingResource($booking);
    }

    public function approve(ApproveBookingRequest $request, BookingStatusEngine $statusEngine, int $id): AdminBookingResource
    {
        $booking = DB::transaction(function () use ($request, $statusEngine, $id) {
            $booking = Booking::lockForUpdate()->findOrFail($id);

            $this->guardTransition($booking, $statusEngine, 'approved', 'approved');

            $booking->dropoff_at = $request->validated('dropoff_at');
            $booking->approved_at = now();
            $booking->approved_by = $request->user()->id;
            $booking->save();

            $statusEngine->transition($booking, 'approved', $request->user()->id);

            return $booking;
        });

        return new AdminBookingResource($booking->load(self::detailEagerLoads()));
    }

    public function reject(RejectBookingRequest $request, BookingStatusEngine $statusEngine, int $id): AdminBookingResource
    {
        $booking = DB::transaction(function () use ($request, $statusEngine, $id) {
            $booking = Booking::lockForUpdate()->findOrFail($id);

            $this->guardTransition($booking, $statusEngine, 'rejected', 'rejected');

            $reason = $request->validated('reason');

            $booking->reject_reason = $reason;
            $booking->save();

            $statusEngine->transition($booking, 'rejected', $request->user()->id, $reason);

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

            foreach ($booking->items as $item) {
                $finalWeightKg = (float) $submitted[$item->id]['final_weight_kg'];
                $subtotal = round((float) $item->rate * $finalWeightKg, 2);

                $item->final_weight_kg = $finalWeightKg;
                $item->subtotal = $subtotal;
                $item->save();

                $totalAmount += $subtotal;
            }

            $booking->total_amount = round($totalAmount, 2);
            $booking->weighed_at = now();
            $booking->confirmed_at = now();
            $booking->confirmed_by = $request->user()->id;
            $booking->save();

            $statusEngine->transition($booking, 'confirmed', $request->user()->id);

            return $booking;
        });

        return new AdminBookingResource($booking->load(self::detailEagerLoads()));
    }

    public function confirmOrder(Request $request, BookingStatusEngine $statusEngine, int $id): AdminBookingResource
    {
        $booking = DB::transaction(function () use ($request, $statusEngine, $id) {
            $booking = Booking::lockForUpdate()->findOrFail($id);

            $this->guardOrderTransition($booking, $statusEngine, 'confirmed', 'confirmed');

            $booking->confirmed_at = now();
            $booking->confirmed_by = $request->user()->id;
            $booking->save();

            $statusEngine->transition($booking, 'confirmed', $request->user()->id);

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

            $booking->reject_reason = $reason;
            $booking->save();

            foreach ($booking->items as $item) {
                Service::whereKey($item->service_id)->increment('stock_qty', $item->qty);

                InventoryLog::create([
                    'service_id' => $item->service_id,
                    'change_qty' => $item->qty,
                    'reason' => 'release',
                    'booking_id' => $booking->id,
                    'created_by' => $request->user()->id,
                ]);
            }

            $statusEngine->transition($booking, 'rejected', $request->user()->id, $reason);

            return $booking;
        });

        return new AdminBookingResource($booking->load(self::detailEagerLoads()));
    }

    /**
     * Approve, reject, and weigh-in are only for bring-your-own bookings - a
     * shop-supplied order reaches `rejected`/`confirmed` through feature 11's
     * own flow instead, which also releases its reserved stock on rejection.
     * `BookingStatusEngine::isAllowed` alone does not scope to source type,
     * since `shop_supplied` can reach both statuses too, so this checks both.
     */
    private function guardTransition(Booking $booking, BookingStatusEngine $statusEngine, string $to, string $action): void
    {
        if (
            $booking->source_type !== 'customer_supplied'
            || ! $statusEngine->isAllowed($booking->source_type, $booking->status, $to)
        ) {
            throw ValidationException::withMessages([
                'status' => "This booking cannot be {$action} right now.",
            ]);
        }
    }

    /**
     * Confirm and reject-order are only for shop-supplied bookings - the
     * bring-your-own counterpart is `guardTransition` above.
     */
    private function guardOrderTransition(Booking $booking, BookingStatusEngine $statusEngine, string $to, string $action): void
    {
        if (
            $booking->source_type !== 'shop_supplied'
            || ! $statusEngine->isAllowed($booking->source_type, $booking->status, $to)
        ) {
            throw ValidationException::withMessages([
                'status' => "This booking cannot be {$action} right now.",
            ]);
        }
    }

    /**
     * @return array<int|string, string|\Closure>
     */
    private static function detailEagerLoads(): array
    {
        return [
            'items.service',
            'customer',
            'latestStatusLog',
            'statusLogs' => fn ($query) => $query->orderBy('id')->with('changer'),
            'approver',
            'confirmer',
        ];
    }
}
