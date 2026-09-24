<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Concerns\LowercasesEmail;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAdminAccountRequest extends FormRequest
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
            'password' => ['required', 'string', 'min:8'],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['distinct', Rule::in(User::MODULES)],
        ];
    }
}
