<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShiftRotation extends Model
{
    protected $fillable = [
        'name', 'sequence', 'days_per_step', 'anchor_date', 'description', 'is_active',
    ];

    protected $casts = [
        'sequence'      => 'array',
        'anchor_date'   => 'date',
        'days_per_step' => 'integer',
        'is_active'     => 'boolean',
    ];

    public function assignments()
    {
        return $this->hasMany(EmployeeRotation::class);
    }
}
