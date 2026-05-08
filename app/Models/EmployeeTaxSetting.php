<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeTaxSetting extends Model
{
    protected $fillable = [
        'employee_id', 'tax_profile_id',
        'tax_method', 'fixed_rate', 'flat_amount',
        'withhold_strategy', 'overrides', 'is_active',
    ];

    protected $casts = [
        'fixed_rate' => 'decimal:2',
        'flat_amount' => 'decimal:2',
        'overrides' => 'array',
        'is_active' => 'bool',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function taxProfile(): BelongsTo
    {
        return $this->belongsTo(TaxProfile::class);
    }
}
