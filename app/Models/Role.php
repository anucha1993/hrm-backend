<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Role extends Model
{
    use HasFactory;

    public const SUPER_ADMIN = 'super_admin';
    public const ADMIN       = 'admin';
    public const MEMBER      = 'member';
    public const EMPLOYEE    = 'employee';
    public const HR          = 'hr';
    public const MANAGER     = 'manager';
    public const OWNER       = 'owner';

    protected $fillable = [
        'name',
        'display_name',
        'description',
        'is_system',
    ];

    protected $casts = [
        'is_system' => 'boolean',
    ];

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permission');
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function hasPermission(string $name): bool
    {
        if ($this->name === self::SUPER_ADMIN) {
            return true;
        }

        return $this->permissions()->where('name', $name)->exists();
    }
}
