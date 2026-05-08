<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TaxBracket extends Model
{
    protected $fillable = [
        'min_income', 'max_income', 'rate',
        'order', 'effective_year', 'is_active',
    ];

    protected $casts = [
        'min_income' => 'decimal:2',
        'max_income' => 'decimal:2',
        'rate' => 'decimal:2',
        'is_active' => 'bool',
    ];
}
