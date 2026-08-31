<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GoodsDepositSlip extends Model
{
    use HasFactory;

    public const STATUS_PENDING   = 'pending';
    public const STATUS_DEDUCTED  = 'deducted';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_WAIVED    = 'waived';

    public const SOURCE_MANUAL    = 'manual';
    public const SOURCE_LABOUR_API = 'labour_api';

    protected $fillable = [
        'slip_no', 'employee_id', 'deposit_date', 'total_amount',
        'status', 'payroll_period_id', 'payslip_id', 'deducted_at',
        'created_by', 'note', 'source',
    ];

    protected $casts = [
        'deposit_date' => 'date',
        'deducted_at'  => 'datetime',
        'total_amount' => 'decimal:2',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(GoodsDepositItem::class, 'deposit_slip_id')->orderBy('order');
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

    public function recalcTotal(): void
    {
        $this->total_amount = $this->items()->sum('amount');
        $this->save();
    }

    public static function generateSlipNo(\DateTimeInterface $date): string
    {
        $prefix = 'GD-' . $date->format('ym') . '-';
        $last   = static::where('slip_no', 'like', $prefix . '%')
            ->orderByDesc('slip_no')->value('slip_no');
        $next   = $last ? ((int) substr($last, -4)) + 1 : 1;
        return $prefix . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }
}
