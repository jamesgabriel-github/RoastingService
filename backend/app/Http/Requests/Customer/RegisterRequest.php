<?php

namespace App\Http\Requests\Customer;

use App\Http\Requests\Concerns\LowercasesEmail;
use App\Support\PhoneNumber;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class RegisterRequest extends FormRequest
{
    use LowercasesEmail;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->lowercaseEmail();

        $phone = $this->input('phone');

        if (is_string($phone)) {
            $this->merge([
                'phone' => PhoneNumber::normalize($phone) ?? $phone,
            ]);
        }
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
            'email' => ['required', 'email', 'unique:users,email'],
            'phone' => ['required', 'regex:/^09\d{9}$/', 'unique:users,phone'],
            'password' => ['required', 'string', 'min:8'],
        ];
    }
}
