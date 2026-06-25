<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShiftSwapRequest extends Model
{
    protected $fillable = [
        'requester_id', 'counterparty_id', 'requester_date', 'counterparty_date',
        'requester_shift_id', 'counterparty_shift_id', 'reason', 'status',
        'approved_by', 'decided_at', 'decision_note', 'created_by',
    ];

    protected $casts = [
        'requester_date'    => 'date',
        'counterparty_date' => 'date',
        'decided_at'        => 'datetime',
    ];

    public function requester(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'requester_id');
    }

    public function counterparty(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'counterparty_id');
    }

    public function requesterShift(): BelongsTo
    {
        return $this->belongsTo(WorkShift::class, 'requester_shift_id');
    }

    public function counterpartyShift(): BelongsTo
    {
        return $this->belongsTo(WorkShift::class, 'counterparty_shift_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function overrides(): HasMany
    {
        return $this->hasMany(ShiftDayOverride::class);
    }

    /** สลับกะวันเดียวกัน (true) หรือสลับวันทำงานกัน (false) */
    public function isSameDay(): bool
    {
        return $this->requester_date->isSameDay($this->counterparty_date);
    }
}
