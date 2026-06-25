<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShiftDayOverride extends Model
{
    protected $fillable = [
        'employee_id', 'date', 'work_shift_id', 'is_day_off',
        'source', 'shift_swap_request_id', 'note', 'created_by',
    ];

    protected $casts = [
        'date'       => 'date',
        'is_day_off' => 'boolean',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function workShift(): BelongsTo
    {
        return $this->belongsTo(WorkShift::class);
    }

    public function swapRequest(): BelongsTo
    {
        return $this->belongsTo(ShiftSwapRequest::class, 'shift_swap_request_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
