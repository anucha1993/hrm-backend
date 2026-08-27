<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Department extends Model
{
    use HasFactory;

    public const ATTENDANCE_FULL = 'full';
    public const ATTENDANCE_CHECK_IN_ONLY = 'check_in_only';
    public const ATTENDANCE_NONE = 'none';
    public const ATTENDANCE_MODES = [
        self::ATTENDANCE_FULL,
        self::ATTENDANCE_CHECK_IN_ONLY,
        self::ATTENDANCE_NONE,
    ];

    protected $fillable = ['code', 'name', 'description', 'work_profile_id', 'attendance_mode', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    protected $attributes = [
        'attendance_mode' => self::ATTENDANCE_FULL,
    ];

    public function tracksAttendance(): bool
    {
        return $this->attendance_mode !== self::ATTENDANCE_NONE;
    }

    public function requiresCheckOut(): bool
    {
        return $this->attendance_mode === self::ATTENDANCE_FULL;
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    public function workProfile(): BelongsTo
    {
        return $this->belongsTo(WorkProfile::class);
    }
}
