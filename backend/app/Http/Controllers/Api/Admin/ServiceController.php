<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreServiceRequest;
use App\Http\Requests\Admin\UpdateServiceRequest;
use App\Http\Resources\ServiceResource;
use App\Models\Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ServiceController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return ServiceResource::collection(Service::all());
    }

    public function store(StoreServiceRequest $request): JsonResponse
    {
        $data = $this->normalize($request->validated());
        $data['stock_qty'] = 0;
        $data['is_active'] = true;

        $service = Service::create($data);

        return (new ServiceResource($service))
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateServiceRequest $request, int $id): ServiceResource
    {
        $service = Service::findOrFail($id);
        $service->update($this->normalize($request->validated()));

        return new ServiceResource($service);
    }

    public function toggle(int $id): ServiceResource
    {
        $service = Service::findOrFail($id);
        $service->is_active = ! $service->is_active;
        $service->save();

        return new ServiceResource($service);
    }

    /**
     * A rate/price for a booking type the service doesn't support would be
     * stale and misleading, so it's always cleared regardless of payload.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalize(array $data): array
    {
        if (! $data['allow_customer_supplied']) {
            $data['roasting_rate_per_kg'] = null;
        }

        if (! $data['allow_shop_supplied']) {
            $data['shop_price'] = null;
        }

        return $data;
    }
}
