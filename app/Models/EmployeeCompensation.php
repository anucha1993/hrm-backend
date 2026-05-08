<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeCompensation extends Model
{
    protected $fillable = [
        'employee_id', 'compensation_profile_id',
        'base_salary', 'hourly_rate_override',
        'effective_from', 'effective_to', 'is_active',
    ];

    protected $casts = [
        'base_salary' => 'decimal:2',
        'hourly_rate_override' => 'decimal:2',
        'effective_from' => 'date',
        'effective_to' => 'date',
        'is_active' => 'bool',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(CompensationProfile::class, 'compensation_profile_id');
    }
}
