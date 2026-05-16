<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class WorkOrder extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'code', 'start_date', 'end_date', 'period_type',
        'team_leader_id', 'location_name', 'total_amount', 'status',
        'payroll_period_id', 'paid_at', 'note', 'created_by',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'total_amount' => 'decimal:2',
        'paid_at' => 'datetime',
    ];

    public function teamLeader(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'team_leader_id');
    }

    public function payrollPeriod(): BelongsTo
    {
        return $this->belongsTo(PayrollPeriod::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(WorkOrderItem::class)->orderBy('sort_order');
    }

    public function members(): HasMany
    {
        return $this->hasMany(WorkOrderMember::class);
    }

    public function dailyEntries(): HasMany
    {
        return $this->hasMany(WorkOrderDailyEntry::class)->orderBy('work_date');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Recompute actual_qty_total ของแต่ละ item จาก daily entries
     * แล้ว recompute rate + total ของแต่ละ item
     * แล้วรวมเป็น total_amount ของใบจ่ายงาน
     */
    public function recalculate(): void
    {
        $this->load(['items.rateItem', 'items.dailyEntryItems']);

        foreach ($this->items as $item) {
            $item->actual_qty_total = $item->dailyEntryItems->sum('actual_qty');
            $item->save();
            $item->recompute();
        }

        $this->total_amount = $this->items->sum('total_amount');
        $this->save();
    }

    /**
     * Auto-generate code เช่น WO-2026051401
     */
    public static function generateCode(?\DateTimeInterface $date = null): string
    {
        $date = $date ?: now();
        $prefix = 'WO-' . $date->format('Ymd');
        $last = static::where('code', 'like', "{$prefix}%")
            ->orderByDesc('code')
            ->withTrashed()
            ->first();
        $seq = $last ? ((int) substr($last->code, -2) + 1) : 1;
        return $prefix . str_pad((string) $seq, 2, '0', STR_PAD_LEFT);
    }
}
