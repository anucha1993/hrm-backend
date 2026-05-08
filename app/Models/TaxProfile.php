<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class TaxProfile extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name', 'description',
        'personal_allowance', 'spouse_allowance',
        'children_count', 'child_allowance_each',
        'parent_allowance', 'disabled_allowance',
        'life_insurance', 'health_insurance', 'provident_fund',
        'rmf_amount', 'ssf_amount', 'home_loan_interest', 'donation_amount',
        'extra_deductions',
        'expense_deduction_rate', 'expense_deduction_max',
        'is_default', 'is_active',
    ];

    protected $casts = [
        'extra_deductions' => 'array',
        'is_default' => 'bool',
        'is_active' => 'bool',
        'personal_allowance' => 'decimal:2',
        'spouse_allowance' => 'decimal:2',
        'child_allowance_each' => 'decimal:2',
        'parent_allowance' => 'decimal:2',
        'disabled_allowance' => 'decimal:2',
        'life_insurance' => 'decimal:2',
        'health_insurance' => 'decimal:2',
        'provident_fund' => 'decimal:2',
        'rmf_amount' => 'decimal:2',
        'ssf_amount' => 'decimal:2',
        'home_loan_interest' => 'decimal:2',
        'donation_amount' => 'decimal:2',
        'expense_deduction_rate' => 'decimal:2',
        'expense_deduction_max' => 'decimal:2',
    ];
}
