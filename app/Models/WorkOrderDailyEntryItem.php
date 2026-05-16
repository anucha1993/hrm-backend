<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkOrderDailyEntryItem extends Model
{
    protected $fillable = ['work_order_daily_entry_id', 'work_order_item_id', 'assigned_qty', 'actual_qty'];

    protected $casts = ['assigned_qty' => 'decimal:2', 'actual_qty' => 'decimal:2'];

    public function dailyEntry(): BelongsTo
    {
        return $this->belongsTo(WorkOrderDailyEntry::class, 'work_order_daily_entry_id');
    }

    public function workOrderItem(): BelongsTo
    {
        return $this->belongsTo(WorkOrderItem::class, 'work_order_item_id');
    }
}
