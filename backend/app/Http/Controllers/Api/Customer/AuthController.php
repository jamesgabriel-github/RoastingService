<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\LoginRequest;
use App\Http\Requests\Customer\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(RegisterRequest $request): JsonResponse
    {
        $user = User::create([
            'name' => $request->string('name'),
            'email' => $request->string('email'),
            'phone' => $request->string('phone'),
            'password' => $request->string('password'),
        ])->refresh();

        Auth::login($user);
        $request->session()->regenerate();

        return (new UserResource($user))
            ->response()
            ->setStatusCode(201);
    }

    public function login(LoginRequest $request): Response
    {
        $user = User::where('phone', $request->string('phone'))->first();

        if (! $user || $user->role !== 'customer' || ! $user->is_active) {
            throw ValidationException::withMessages([
                'phone' => __('auth.failed'),
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
