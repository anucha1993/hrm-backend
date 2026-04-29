<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role_id',
        'is_active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password'          => 'hashed',
            'is_active'         => 'boolean',
        ];
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function isSuperAdmin(): bool
    {
        return $this->role?->name === Role::SUPER_ADMIN;
    }

    public function hasRole(string|array $names): bool
    {
        $names = (array) $names;
        return in_array($this->role?->name, $names, true);
    }

    public function hasPermission(string $name): bool
    {
        if (! $this->is_active) {
            return false;
        }
        if ($this->isSuperAdmin()) {
            return true;
        }
        return (bool) $this->role?->hasPermission($name);
    }

    public function getPermissionNames(): array
    {
        if ($this->isSuperAdmin()) {
            return Permission::pluck('name')->all();
        }
        return $this->role?->permissions()->pluck('name')->all() ?? [];
    }
}
