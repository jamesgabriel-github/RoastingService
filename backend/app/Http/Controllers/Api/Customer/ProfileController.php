<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\CompleteProfileRequest;
use App\Http\Resources\UserResource;

class ProfileController extends Controller
{
    public function complete(CompleteProfileRequest $request): UserResource
    {
        $user = $request->user();

        if ($user->first_name !== null && $user->last_name !== null) {
            abort(409, 'Profile is already complete.');
        }

        $user->update($request->validated());

        return new UserResource($user);
    }

    public function update(CompleteProfileRequest $request): UserResource
    {
        $user = $request->user();
        $user->update($request->validated());

        return new UserResource($user);
    }
}
