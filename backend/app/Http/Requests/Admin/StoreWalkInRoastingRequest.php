<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWalkInRoastingRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'customer_id' => [
                'nullable',
                'integer',
                Rule::exists('users', 'id')->where(fn ($query) => $query->where('role', 'customer')),
                'required_without_all:guest_name,guest_phone',
                'prohibits:guest_name,guest_phone',
            ],
            'guest_name' => ['nullable', 'string', 'max:255', 'required_with:guest_phone', 'prohibits:customer_id'],
            'guest_phone' => ['nullable', 'string', 'max:32', 'required_with:guest_name', 'prohibits:customer_id'],
            'items' => ['required', 'array', 'min:1', 'max:20'],
            'items.*.service_id' => [
                'required',
                'integer',
                Rule::exists('services', 'id')->where(function ($query) {
                    $query->where('allow_customer_supplied', true)->where('is_active', true);
                }),
            ],
            'items.*.final_weight_kg' => ['required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:1000'],
            'fulfillment' => ['required', 'string', 'in:pickup,delivery'],
            'delivery_address' => ['nullable', 'string', 'max:500', 'required_if:fulfillment,delivery'],
            'preferred_pickup_at' => ['required', 'date', 'after_or_equal:now'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
