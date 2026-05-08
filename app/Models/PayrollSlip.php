<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayrollSlip extends Model
{
    protected $fillable = [
        'slip_no', 'payroll_period_id', 'employee_id',
        'compensation_profile_id', 'tax_profile_id',
        'profile_snapshot', 'tax_snapshot',
        'base_salary', 'hourly_rate', 'daily_rate',
        'working_days', 'present_days', 'absent_days', 'leave_days',
        'late_count', 'late_minutes_total', 'ot_hours_total',
        'base_pay', 'ot_pay', 'allowances_total', 'bonus_total', 'gross_pay',
        'late_deduction', 'absent_deduction', 'other_deductions_total',
        'ssf_employee', 'ssf_employer', 'tax', 'deductions_total', 'net_pay',
        'status',
        'approved_l1_by', 'approved_l1_at',
        'approved_l2_by', 'approved_l2_at',
        'paid_by', 'paid_at', 'payment_reference',
        'note', 'calculation_log',
    ];

    protected $casts = [
        'profile_snapshot' => 'array',
        'tax_snapshot' => 'array',
        'calculation_log' => 'array',
        'base_salary' => 'decimal:2',
        'hourly_rate' => 'decimal:2',
        'daily_rate' => 'decimal:2',
        'ot_hours_total' => 'decimal:2',
        'base_pay' => 'decimal:2',
        'ot_pay' => 'decimal:2',
        'allowances_total' => 'decimal:2',
        'bonus_total' => 'decimal:2',
        'gross_pay' => 'decimal:2',
        'late_deduction' => 'decimal:2',
        'absent_deduction' => 'decimal:2',
        'other_deductions_total' => 'decimal:2',
        'ssf_employee' => 'decimal:2',
        'ssf_employer' => 'decimal:2',
        'tax' => 'decimal:2',
        'deductions_total' => 'decimal:2',
        'net_pay' => 'decimal:2',
        'approved_l1_at' => 'datetime',
        'approved_l2_at' => 'datetime',
        'paid_at' => 'datetime',
    ];

    public function period(): BelongsTo
    {
        return $this->belongsTo(PayrollPeriod::class, 'payroll_period_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PayrollSlipItem::class)->orderBy('order');
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(PayrollApproval::class);
    }
}
