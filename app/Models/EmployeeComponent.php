<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeComponent extends Model
{
    protected $fillable = [
        'employee_id', 'compensation_component_id',
        'amount', 'start_date', 'end_date',
        'total_installments', 'paid_installments',
        'note', 'is_active',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'start_date' => 'date',
        'end_date' => 'date',
        'is_active' => 'bool',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(CompensationComponent::class, 'compensation_component_id');
    }
}
