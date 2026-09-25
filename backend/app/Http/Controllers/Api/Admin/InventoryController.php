<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreAdjustRequest;
use App\Http\Requests\Admin\StoreRestockRequest;
use App\Http\Resources\InventoryLogResource;
use App\Http\Resources\ServiceResource;
use App\Models\InventoryLog;
use App\Models\Service;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return ServiceResource::collection(
            Service::where('allow_shop_supplied', true)->get()
        );
    }

    public function restock(StoreRestockRequest $request, int $service): ServiceResource
    {
        return new ServiceResource($this->applyChange(
            $service,
            $request->validated('qty'),
            'restock',
            $request->validated('remarks'),
            $request->user()->id
        ));
    }

    public function adjust(StoreAdjustRequest $request, int $service): ServiceResource
    {
        return new ServiceResource($this->applyChange(
            $service,
            $request->validated('change_qty'),
            'adjust',
            $request->validated('remarks'),
            $request->user()->id
        ));
    }

    public function logs(): AnonymousResourceCollection
    {
        return InventoryLogResource::collection(
            InventoryLog::with(['service', 'creator'])
                ->orderByDesc('id')
                ->paginate()
        );
    }

    private function applyChange(int $serviceId, int $changeQty, string $reason, ?string $remarks, int $userId): Service
    {
        return DB::transaction(function () use ($serviceId, $changeQty, $reason, $remarks, $userId) {
            $service = Service::where('allow_shop_supplied', true)
                ->lockForUpdate()
                ->findOrFail($serviceId);

            $newStock = $service->stock_qty + $changeQty;

            if ($newStock < 0) {
                throw ValidationException::withMessages([
                    'change_qty' => 'This change would take stock below zero.',
                ]);
            }

            $service->stock_qty = $newStock;
            $service->save();

            InventoryLog::create([
                'service_id' => $service->id,
                'change_qty' => $changeQty,
                'reason' => $reason,
                'remarks' => $remarks,
                'created_by' => $userId,
            ]);

            return $service;
        });
    }
}
