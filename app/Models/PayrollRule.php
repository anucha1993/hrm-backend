<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class PayrollRule extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'code', 'name', 'type', 'trigger',
        'accumulation_mode', 'threshold', 'comparison', 'tiers',
        'amount_type', 'amount', 'formula',
        'disqualifiers',
        'min_per_period', 'max_per_period',
        'period', 'priority', 'active',
        'effective_from', 'effective_to', 'note',
        'department_ids', 'apply_months',
        'created_by', 'updated_by',
    ];

    protected $casts = [
        'tiers'           => 'array',
        'disqualifiers'   => 'array',
        'department_ids'  => 'array',
        'apply_months'    => 'array',
        'active'          => 'boolean',
        'amount'          => 'decimal:2',
        'min_per_period'  => 'decimal:2',
        'max_per_period'  => 'decimal:2',
        'threshold'       => 'integer',
        'priority'        => 'integer',
        'effective_from'  => 'date:Y-m-d',
        'effective_to'    => 'date:Y-m-d',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public static function generateCode(string $type): string
    {
        $prefix = $type === 'bonus' ? 'BON-' : 'DED-';
        $last = static::withTrashed()->where('code', 'like', $prefix . '%')
            ->orderBy('id', 'desc')->first();
        $next = 1;
        if ($last) {
            $next = ((int) substr($last->code, -4)) + 1;
        }
        return $prefix . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }
}
