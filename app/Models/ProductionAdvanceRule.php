<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductionAdvanceRule extends Model
{
    use SoftDeletes;

    public const SCOPE_COMPANY = 'company';
    public const SCOPE_DEPARTMENT = 'department';

    public const METRIC_PRODUCTION_QTY = 'production_qty';
    public const METRIC_ATTENDANCE_DAYS = 'attendance_days';

    protected $fillable = [
        'name', 'unit', 'target_qty', 'scope', 'department_id',
        'metric_type', 'applies_to_department_ids',
        'is_active', 'note', 'created_by',
    ];

    protected $casts = [
        'target_qty' => 'decimal:2',
        'is_active' => 'boolean',
        'applies_to_department_ids' => 'array',
    ];

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function productionRateItems(): BelongsToMany
    {
        return $this->belongsToMany(
            ProductionRateItem::class,
            'production_advance_rule_items',
            'production_advance_rule_id',
            'production_rate_item_id',
        )->withTimestamps();
    }
}
