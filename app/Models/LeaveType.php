<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class LeaveType extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'code', 'name', 'name_en', 'color',
        'is_paid', 'requires_approval', 'requires_attachment',
        'counts_as_workday', 'affects_diligence',
        'default_quota_days', 'min_advance_notice_days',
        'allow_half_day', 'allow_negative_balance', 'max_consecutive_days',
        'description', 'order', 'is_active',
    ];

    protected $casts = [
        'is_paid' => 'boolean',
        'requires_approval' => 'boolean',
        'requires_attachment' => 'boolean',
        'counts_as_workday' => 'boolean',
        'affects_diligence' => 'boolean',
        'allow_half_day' => 'boolean',
        'allow_negative_balance' => 'boolean',
        'is_active' => 'boolean',
        'default_quota_days' => 'decimal:2',
        'min_advance_notice_days' => 'integer',
        'max_consecutive_days' => 'integer',
        'order' => 'integer',
    ];

    public function requests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }

    public function balances(): HasMany
    {
        return $this->hasMany(LeaveBalance::class);
    }
}
