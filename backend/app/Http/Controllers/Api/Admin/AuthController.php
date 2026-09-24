<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\LoginRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(LoginRequest $request): Response
    {
        $user = User::where('email', $request->string('email'))->first();

        if (
            ! $user
            || ! in_array($user->role, ['admin', 'super_admin'], true)
            || ! $user->is_active
            || ! Hash::check($request->string('password'), $user->password)
        ) {
            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        Auth::login($user);
        $request->session()->regenerate();

        return response()->noContent();
    }

    public function logout(Request $request): Response
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }
}
