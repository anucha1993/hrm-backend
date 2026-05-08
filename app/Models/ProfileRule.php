<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProfileRule extends Model
{
    protected $fillable = [
        'compensation_profile_id', 'name',
        'trigger', 'operator', 'threshold', 'threshold_max',
        'action', 'amount_type', 'amount', 'scope',
        'taxable', 'affects_ssf', 'priority', 'is_active',
    ];

    protected $casts = [
        'threshold' => 'decimal:2',
        'threshold_max' => 'decimal:2',
        'amount' => 'decimal:2',
        'taxable' => 'bool',
        'affects_ssf' => 'bool',
        'is_active' => 'bool',
    ];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(CompensationProfile::class, 'compensation_profile_id');
    }

    /**
     * ทดสอบเงื่อนไขกับค่า metric ที่คำนวณได้
     */
    public function matches(float $value): bool
    {
        $a = (float) $this->threshold;
        $b = (float) $this->threshold_max;

        return match ($this->operator) {
            'eq'      => $value == $a,
            'lte'     => $value <= $a,
            'gte'     => $value >= $a,
            'lt'      => $value < $a,
            'gt'      => $value > $a,
            'between' => $value >= $a && $value <= $b,
            default   => false,
        };
    }
}
