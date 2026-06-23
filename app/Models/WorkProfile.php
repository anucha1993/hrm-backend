<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkProfile extends Model
{
    protected $fillable = [
        'name', 'work_shift_id', 'work_days', 'description', 'is_default', 'is_active',
    ];

    protected $casts = [
        'work_days'  => 'array',
        'is_default' => 'boolean',
        'is_active'  => 'boolean',
    ];

    public function workShift(): BelongsTo
    {
        return $this->belongsTo(WorkShift::class);
    }

    public function holidays(): HasMany
    {
        return $this->hasMany(Holiday::class);
    }

    public function departments(): HasMany
    {
        return $this->hasMany(Department::class);
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }
}
