<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ElectricityBillInstallment extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_DEDUCTED = 'deducted';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_WAIVED = 'waived';

    protected $fillable = [
        'electricity_bill_item_id', 'employee_id', 'installment_no', 'due_date',
        'amount', 'status', 'payroll_period_id', 'payslip_id', 'deducted_at',
    ];

    protected $casts = [
        'due_date' => 'date',
        'amount' => 'decimal:2',
        'deducted_at' => 'datetime',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(ElectricityBillItem::class, 'electricity_bill_item_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function payrollPeriod(): BelongsTo
    {
        return $this->belongsTo(PayrollPeriod::class);
    }

    public function payslip(): BelongsTo
    {
        return $this->belongsTo(PayrollSlip::class, 'payslip_id');
    }
}
