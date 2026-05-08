<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveBalance extends Model
{
    protected $fillable = [
        'employee_id', 'leave_type_id', 'year',
        'quota_days', 'carryover_days', 'used_days', 'pending_days', 'note',
    ];

    protected $casts = [
        'year' => 'integer',
        'quota_days' => 'decimal:2',
        'carryover_days' => 'decimal:2',
        'used_days' => 'decimal:2',
        'pending_days' => 'decimal:2',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    public function getRemainingAttribute(): float
    {
        return (float) $this->quota_days + (float) $this->carryover_days - (float) $this->used_days - (float) $this->pending_days;
    }

    public function getTotalAvailableAttribute(): float
    {
        return (float) $this->quota_days + (float) $this->carryover_days;
    }
}
