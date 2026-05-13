<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductionRateItem extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'code', 'name', 'category', 'work_type', 'unit',
        'target_qty', 'rate_at_target', 'rate_below_target',
        'note', 'is_active', 'sort_order',
    ];

    protected $casts = [
        'target_qty' => 'decimal:2',
        'rate_at_target' => 'decimal:2',
        'rate_below_target' => 'decimal:2',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    /**
     * คำนวณค่าจ้างจากจำนวน production
     */
    public function calculatePay(float $quantity): float
    {
        if ($this->work_type === 'flat' || $this->target_qty === null) {
            return round($quantity * (float) $this->rate_at_target, 2);
        }
        $rate = $quantity >= (float) $this->target_qty
            ? (float) $this->rate_at_target
            : (float) ($this->rate_below_target ?? $this->rate_at_target);
        return round($quantity * $rate, 2);
    }
}
