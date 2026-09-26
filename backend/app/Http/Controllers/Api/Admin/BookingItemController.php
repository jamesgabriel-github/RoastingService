<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\AdminBookingResource;
use App\Models\Booking;
use App\Models\BookingItem;
use App\Services\Booking\BookingStatusEngine;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BookingItemController extends Controller
{
    public function startCooking(Request $request, BookingStatusEngine $statusEngine, int $id): AdminBookingResource
    {
        $booking = $this->transitionItem($request, $statusEngine, $id, 'cooking', 'started', function (BookingItem $item) {
            $item->load('service');
            $item->cooking_started_at = now();
            $item->est_ready_at = now()->addMinutes($item->service->est_minutes);
        });

        return new AdminBookingResource($booking->load(BookingController::detailEagerLoads()));
    }

    public function ready(Request $request, BookingStatusEngine $statusEngine, int $id): AdminBookingResource
    {
        $booking = $this->transitionItem($request, $statusEngine, $id, 'ready', 'marked ready', function (BookingItem $item) {
            if ($item->booking->fulfillment !== 'pickup') {
                throw ValidationException::withMessages([
                    'status' => 'This item cannot be marked ready right now.',
                ]);
            }
        });

        return new AdminBookingResource($booking->load(BookingController::detailEagerLoads()));
    }

    public function outForDelivery(Request $request, BookingStatusEngine $statusEngine, int $id): AdminBookingResource
    {
        $booking = $this->transitionItem($request, $statusEngine, $id, 'out_for_delivery', 'marked out for delivery', function (BookingItem $item) {
            if ($item->booking->fulfillment !== 'delivery') {
                throw ValidationException::withMessages([
                    'status' => 'This item cannot be marked out for delivery right now.',
                ]);
            }
        });

        return new AdminBookingResource($booking->load(BookingController::detailEagerLoads()));
    }

    public function complete(Request $request, BookingStatusEngine $statusEngine, int $id): AdminBookingResource
    {
        $booking = $this->transitionItem($request, $statusEngine, $id, 'completed', 'completed', function (BookingItem $item) {
            $item->completed_at = now();
        });

        return new AdminBookingResource($booking->load(BookingController::detailEagerLoads()));
    }

    /**
     * Load `$id`, guard its own status against `$to`, run `$beforeTransition`
     * (which may set item fields or throw a guard error of its own), save,
     * transition, and return the item's parent booking.
     */
    private function transitionItem(
        Request $request,
        BookingStatusEngine $statusEngine,
        int $id,
        string $to,
        string $action,
        Closure $beforeTransition
    ): Booking {
        return DB::transaction(function () use ($request, $statusEngine, $id, $to, $action, $beforeTransition) {
            $bookingId = BookingItem::where('id', $id)->value('booking_id');

            if ($bookingId === null) {
                abort(404);
            }

            // Lock the parent booking first, matching the lock target every
            // whole-booking admin action takes, so the two paths serialize
            // instead of racing on the same item.
            $booking = Booking::lockForUpdate()->findOrFail($bookingId);
            $item = BookingItem::lockForUpdate()->findOrFail($id);
            $item->setRelation('booking', $booking);

            if (! $statusEngine->isAllowed($item->booking->is_order, $item->status, $to)) {
                throw ValidationException::withMessages([
                    'status' => "This item cannot be {$action} right now.",
                ]);
            }

            $beforeTransition($item);
            $item->save();

            $statusEngine->transition($item, $to, $request->user()->id);

            return $item->booking;
        });
    }
}
