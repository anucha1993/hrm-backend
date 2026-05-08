<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CompensationComponent extends Model
{
    protected $fillable = [
        'code', 'name', 'kind',
        'default_amount', 'taxable', 'affects_ssf', 'is_active',
    ];

    protected $casts = [
        'default_amount' => 'decimal:2',
        'taxable' => 'bool',
        'affects_ssf' => 'bool',
        'is_active' => 'bool',
    ];
}
