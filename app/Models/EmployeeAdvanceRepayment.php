<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeAdvanceRepayment extends Model
{
    protected $fillable = [
        'employee_advance_id', 'payroll_period_id', 'amount', 'repaid_at', 'note', 'recorded_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'repaid_at' => 'date',
    ];

    public function advance(): BelongsTo
    {
        return $this->belongsTo(EmployeeAdvance::class, 'employee_advance_id');
    }

    public function payrollPeriod(): BelongsTo
    {
        return $this->belongsTo(PayrollPeriod::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
