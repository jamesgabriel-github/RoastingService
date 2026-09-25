<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreServiceRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'est_minutes' => ['required', 'integer', 'min:1'],
            'allow_customer_supplied' => ['required', 'boolean'],
            'allow_shop_supplied' => ['required', 'boolean'],
            'roasting_rate_per_kg' => ['nullable', 'numeric', 'min:0', 'required_if:allow_customer_supplied,true'],
            'shop_price' => ['nullable', 'numeric', 'min:0', 'required_if:allow_shop_supplied,true'],
            'low_stock_threshold' => ['required', 'integer', 'min:0'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if (! $this->boolean('allow_customer_supplied') && ! $this->boolean('allow_shop_supplied')) {
                $validator->errors()->add('allow_shop_supplied', 'At least one booking type must be enabled.');
            }
        });
    }
}
