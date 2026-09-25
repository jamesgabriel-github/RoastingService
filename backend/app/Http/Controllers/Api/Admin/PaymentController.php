<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\PaymentResource;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PaymentController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'method' => ['nullable', 'string', 'in:cash,gcash,card'],
            'search' => ['nullable', 'string', 'max:255'],
        ]);

        $query = Payment::with(['booking.customer', 'recorder']);

        if (! empty($validated['method'])) {
            $query->where('method', $validated['method']);
        }

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

        $query->orderByDesc('id');

        return PaymentResource::collection($query->paginate());
    }
}
