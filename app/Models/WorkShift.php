<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkShift extends Model
{
    protected $fillable = [
        'name', 'start_time', 'end_time', 'break_minutes',
        'late_grace_minutes', 'cross_midnight', 'is_active',
    ];

    protected $casts = [
        'break_minutes'      => 'integer',
        'late_grace_minutes' => 'integer',
        'cross_midnight'     => 'boolean',
        'is_active'          => 'boolean',
    ];
}
