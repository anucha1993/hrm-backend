<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeRotation extends Model
{
    protected $fillable = [
        'employee_id', 'shift_rotation_id', 'offset', 'effective_from', 'effective_to',
    ];

    protected $casts = [
        'offset'         => 'integer',
        'effective_from' => 'date',
        'effective_to'   => 'date',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function rotation(): BelongsTo
    {
        return $this->belongsTo(ShiftRotation::class, 'shift_rotation_id');
    }
}
