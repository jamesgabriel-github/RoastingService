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
        $user->update($request->validated());

        return new UserResource($user);
    }
}
