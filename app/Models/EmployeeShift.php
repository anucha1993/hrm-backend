<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeShift extends Model
{
    protected $fillable = [
        'employee_id', 'work_shift_id', 'effective_from', 'effective_to', 'work_days',
    ];

    protected $casts = [
        'effective_from' => 'date',
        'effective_to'   => 'date',
        'work_days'      => 'array',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function workShift(): BelongsTo
    {
        return $this->belongsTo(WorkShift::class);
    }
}
