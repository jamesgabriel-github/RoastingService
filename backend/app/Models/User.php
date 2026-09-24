<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'phone', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /** Modules a super admin always has access to, regardless of granted permissions. */
    public const MODULES = ['services', 'inventory', 'bookings', 'payments', 'dashboard'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<AdminPermission, $this>
     */
    public function permissions(): HasMany
    {
        return $this->hasMany(AdminPermission::class);
    }

    public function hasModulePermission(string $module): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if ($this->role === 'super_admin') {
            return true;
        }

        if ($this->role !== 'admin') {
            return false;
        }

        return $this->permissions()->where('module', $module)->exists();
    }
}
