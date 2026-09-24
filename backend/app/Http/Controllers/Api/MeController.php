<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use Illuminate\Http\Request;

class MeController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request): UserResource
    {
        return new UserResource($request->user());
    }
}
