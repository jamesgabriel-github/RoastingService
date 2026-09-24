<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreAdminAccountRequest;
use App\Http\Requests\Admin\UpdateAdminAccountRequest;
use App\Http\Resources\UserResource;
use App\Models\AdminPermission;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class AdminAccountController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return UserResource::collection(
            User::where('role', 'admin')->with('permissions')->get()
        );
    }

    public function store(StoreAdminAccountRequest $request): JsonResponse
    {
        $admin = DB::transaction(function () use ($request) {
            $admin = User::create([
                'name' => $request->string('name'),
                'email' => $request->string('email'),
                'password' => $request->string('password'),
            ]);

            $admin->forceFill(['role' => 'admin'])->save();

            $this->syncPermissions($admin, $request->input('permissions', []), $request->user());

            return $admin;
        });

        return (new UserResource($admin->fresh('permissions')))
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateAdminAccountRequest $request, int $id): UserResource
    {
        $admin = User::where('role', 'admin')->findOrFail($id);

        if ($request->has('is_active')) {
            $admin->is_active = $request->boolean('is_active');
            $admin->save();
        }

        if ($request->has('permissions')) {
            $this->syncPermissions($admin, $request->input('permissions', []), $request->user());
        }

        return new UserResource($admin->fresh('permissions'));
    }

    /**
     * @param  array<int, string>  $modules
     */
    private function syncPermissions(User $admin, array $modules, User $grantedBy): void
    {
        DB::transaction(function () use ($admin, $modules, $grantedBy) {
            $admin->permissions()->delete();

            foreach ($modules as $module) {
                AdminPermission::create([
                    'user_id' => $admin->id,
                    'module' => $module,
                    'granted_by' => $grantedBy->id,
                ]);
            }
        });
    }
}
