<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkOrderDailyEntry extends Model
{
    protected $fillable = ['work_order_id', 'work_date', 'note'];

    protected $casts = ['work_date' => 'date'];

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(WorkOrderDailyEntryItem::class);
    }
}
