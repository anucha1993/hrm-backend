<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkOrderExtraItem extends Model
{
    protected $fillable = [
        'work_order_id', 'name', 'unit', 'qty', 'rate', 'amount', 'note', 'sort_order',
    ];

    protected $casts = [
        'qty' => 'decimal:2',
        'rate' => 'decimal:2',
        'amount' => 'decimal:2',
    ];

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function recompute(): void
    {
        $this->amount = round((float) $this->qty * (float) $this->rate, 2);
    }
}
