<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CompensationProfile extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name', 'description', 'pay_frequency',
        'working_days_per_period', 'working_hours_per_day',
        'ot_rate_normal', 'ot_rate_holiday', 'ot_rate_holiday_overtime',
        'late_deduction_method', 'late_deduction_rate', 'late_grace_minutes',
        'absent_deduction_method', 'absent_deduction_amount',
        'ssf_enabled', 'ssf_rate', 'ssf_min_base', 'ssf_max_base',
        'is_default', 'is_active',
    ];

    protected $casts = [
        'ssf_enabled' => 'bool',
        'is_default'  => 'bool',
        'is_active'   => 'bool',
        'ot_rate_normal' => 'decimal:2',
        'ot_rate_holiday' => 'decimal:2',
        'ot_rate_holiday_overtime' => 'decimal:2',
        'late_deduction_rate' => 'decimal:2',
        'absent_deduction_amount' => 'decimal:2',
        'ssf_rate' => 'decimal:2',
        'ssf_min_base' => 'decimal:2',
        'ssf_max_base' => 'decimal:2',
    ];

    public function rules(): HasMany
    {
        return $this->hasMany(ProfileRule::class)->orderBy('priority');
    }

    public function employeeCompensations(): HasMany
    {
        return $this->hasMany(EmployeeCompensation::class);
    }
}
