<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class EmployeeAdvance extends Model
{
    use SoftDeletes;

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_PAID = 'paid';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'request_no', 'employee_id', 'amount', 'reason', 'request_date',
        'repaid_amount', 'status',
        'approved_by', 'approved_at', 'approval_note',
        'paid_by', 'paid_at', 'created_by',
        'disbursement_method', 'production_advance_rule_id',
        'tiger_voucher_code', 'tiger_voucher_ref_num', 'tiger_voucher_status',
        'tiger_voucher_response', 'tiger_voucher_issued_at',
        'eligibility_bypassed', 'eligibility_bypass_reason', 'eligibility_bypass_by', 'eligibility_bypass_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'repaid_amount' => 'decimal:2',
        'request_date' => 'date',
        'approved_at' => 'datetime',
        'paid_at' => 'datetime',
        'tiger_voucher_response' => 'array',
        'tiger_voucher_issued_at' => 'datetime',
        'eligibility_bypassed' => 'boolean',
        'eligibility_bypass_at' => 'datetime',
    ];

    protected $appends = ['remaining_amount'];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function repayments(): HasMany
    {
        return $this->hasMany(EmployeeAdvanceRepayment::class);
    }

    public function productionAdvanceRule(): BelongsTo
    {
        return $this->belongsTo(ProductionAdvanceRule::class);
    }

    public function bypassedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'eligibility_bypass_by');
    }

    public function getRemainingAmountAttribute(): float
    {
        return round((float) $this->amount - (float) $this->repaid_amount, 2);
    }
}
