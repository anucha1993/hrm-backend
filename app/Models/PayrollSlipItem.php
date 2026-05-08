<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollSlipItem extends Model
{
    protected $fillable = [
        'payroll_slip_id', 'type', 'source', 'code', 'name',
        'amount', 'quantity', 'rate',
        'taxable', 'affects_ssf',
        'formula', 'reference_id', 'reference_type', 'order',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'quantity' => 'decimal:2',
        'rate' => 'decimal:2',
        'taxable' => 'bool',
        'affects_ssf' => 'bool',
    ];

    public function slip(): BelongsTo
    {
        return $this->belongsTo(PayrollSlip::class, 'payroll_slip_id');
    }
}
