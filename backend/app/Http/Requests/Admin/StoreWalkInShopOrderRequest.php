<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreWalkInShopOrderRequest extends FormRequest
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
                'prohibits:guest_name,guest_phone',
            ],
            'guest_name' => ['nullable', 'string', 'max:255', 'prohibits:customer_id'],
            'guest_phone' => ['nullable', 'string', 'max:32', 'prohibits:customer_id'],
            'items' => ['required', 'array', 'min:1', 'max:20'],
            'items.*.service_id' => [
                'required',
                'integer',
                Rule::exists('services', 'id')->where(function ($query) {
                    $query->where('allow_shop_supplied', true)->where('is_active', true);
                }),
            ],
            'items.*.qty' => ['required', 'integer', 'min:1', 'max:1000'],
            'items.*.est_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
            'fulfillment' => ['required', 'string', 'in:pickup,delivery'],
            'delivery_address' => ['nullable', 'string', 'max:500', 'required_if:fulfillment,delivery'],
            'preferred_pickup_at' => ['required', 'date', 'after_or_equal:now'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'paid_amount' => ['nullable', 'numeric', 'min:0'],
            'payment_method' => ['nullable', 'string', 'in:cash,gcash,card'],
            'payment_reference_no' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->sometimes('payment_method', ['required'], fn ($input) => (float) ($input->paid_amount ?? 0) > 0);
    }
}
