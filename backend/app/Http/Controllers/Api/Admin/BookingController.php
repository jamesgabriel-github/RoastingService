<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\AdminBookingResource;
use App\Models\Booking;
use App\Models\BookingStatusLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

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
        $booking = Booking::with([
            'items.service',
            'customer',
            'latestStatusLog',
            'statusLogs' => fn ($query) => $query->orderBy('id')->with('changer'),
        ])->findOrFail($id);

        return new AdminBookingResource($booking);
    }
}
