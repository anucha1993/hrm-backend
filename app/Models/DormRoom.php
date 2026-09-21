<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DormRoom extends Model
{
    protected $fillable = [
        'room_no', 'employee_id', 'rent_amount', 'last_meter_reading', 'is_active', 'note',
    ];

    protected $casts = [
        'rent_amount' => 'decimal:2',
        'last_meter_reading' => 'decimal:2',
        'is_active' => 'bool',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ElectricityBillItem::class);
    }
}
