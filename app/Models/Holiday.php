<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Holiday extends Model
{
    protected $fillable = [
        'work_profile_id', 'name', 'date', 'is_recurring', 'is_working', 'is_active',
    ];

    protected $casts = [
        'date'         => 'date',
        'is_recurring' => 'boolean',
        'is_working'   => 'boolean',
        'is_active'    => 'boolean',
    ];

    public function workProfile(): BelongsTo
    {
        return $this->belongsTo(WorkProfile::class);
    }
}
