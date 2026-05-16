<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkOrderItem extends Model
{
    protected $fillable = [
        'work_order_id', 'production_rate_item_id',
        'target_qty', 'actual_qty_total',
        'rate_at_target_override', 'rate_below_target_override',
        'rate_used', 'total_amount', 'sort_order',
    ];

    protected $casts = [
        'target_qty' => 'decimal:2',
        'actual_qty_total' => 'decimal:2',
        'rate_at_target_override' => 'decimal:2',
        'rate_below_target_override' => 'decimal:2',
        'rate_used' => 'decimal:2',
        'total_amount' => 'decimal:2',
    ];

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function rateItem(): BelongsTo
    {
        return $this->belongsTo(ProductionRateItem::class, 'production_rate_item_id');
    }

    public function dailyEntryItems(): HasMany
    {
        return $this->hasMany(WorkOrderDailyEntryItem::class);
    }

    /**
     * คำนวณ rate + total จาก actual_qty_total เทียบ target_qty
     * - flat หรือ target<=0 → high rate เสมอ
     * - actual >= target → high; else low
     * - override (ถ้ามี) ทับเรทจาก rate item
     */
    public function recompute(): void
    {
        $rate = $this->rateItem;
        if (!$rate) return;

        $actual = (float) $this->actual_qty_total;
        $target = (float) $this->target_qty;

        $high = $this->rate_at_target_override !== null
            ? (float) $this->rate_at_target_override
            : (float) $rate->rate_at_target;
        $low = $this->rate_below_target_override !== null
            ? (float) $this->rate_below_target_override
            : ($rate->rate_below_target !== null ? (float) $rate->rate_below_target : $high);

        if ($rate->work_type === 'flat' || $target <= 0) {
            $rateUsed = $high;
        } else {
            $rateUsed = $actual >= $target ? $high : $low;
        }

        $this->update([
            'rate_used' => $rateUsed,
            'total_amount' => round($actual * $rateUsed, 2),
        ]);
    }
}
