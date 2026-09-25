<?php

namespace App\Http\Requests\Customer;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBookingRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->role === 'customer';
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1', 'max:20'],
            'items.*.service_id' => [
                'required',
                'integer',
                Rule::exists('services', 'id')->where(function ($query) {
                    $query->where('allow_customer_supplied', true)->where('is_active', true);
                }),
            ],
            'items.*.est_weight_kg' => ['required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:1000'],
            'fulfillment' => ['required', 'string', 'in:pickup,delivery'],
            'delivery_address' => ['nullable', 'string', 'max:500', 'required_if:fulfillment,delivery'],
            'preferred_dropoff_at' => ['required', 'date', 'after:now'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
