<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Commission extends Model
{
    public const STATUS_PENDING   = 'pending';
    public const STATUS_PAID      = 'paid';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'code', 'employee_id', 'earned_date', 'amount', 'title', 'note',
        'status', 'payroll_period_id', 'payslip_id', 'paid_at', 'created_by',
    ];

    protected $casts = [
        'earned_date' => 'date',
        'amount'      => 'decimal:2',
        'paid_at'     => 'datetime',
    ];

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

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public static function generateCode(\DateTimeInterface $date): string
    {
        $prefix = 'COMM-' . $date->format('ym') . '-';
        $last   = static::where('code', 'like', $prefix . '%')
            ->orderByDesc('code')->value('code');
        $next   = $last ? ((int) substr($last, -4)) + 1 : 1;
        return $prefix . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }
}
