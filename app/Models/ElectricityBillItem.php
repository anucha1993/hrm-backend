<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ElectricityBillItem extends Model
{
    protected $fillable = [
        'electricity_bill_id', 'dorm_room_id', 'employee_id',
        'meter_start', 'meter_end', 'units_used', 'rate_per_unit',
        'electricity_amount', 'room_rent', 'total_amount', 'note', 'order',
    ];

    protected $casts = [
        'meter_start' => 'decimal:2',
        'meter_end' => 'decimal:2',
        'units_used' => 'decimal:2',
        'rate_per_unit' => 'decimal:2',
        'electricity_amount' => 'decimal:2',
        'room_rent' => 'decimal:2',
        'total_amount' => 'decimal:2',
    ];

    public function bill(): BelongsTo
    {
        return $this->belongsTo(ElectricityBill::class, 'electricity_bill_id');
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(DormRoom::class, 'dorm_room_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function installments(): HasMany
    {
        return $this->hasMany(ElectricityBillInstallment::class);
    }

    /**
     * คำนวณ units_used / electricity_amount / total_amount จาก meter_start/meter_end ปัจจุบัน
     */
    public function recompute(): void
    {
        $units = $this->meter_end !== null ? max(0, (float) $this->meter_end - (float) $this->meter_start) : 0;
        $elec = round($units * (float) $this->rate_per_unit, 2);
        $this->units_used = $units;
        $this->electricity_amount = $elec;
        $this->total_amount = round($elec + (float) $this->room_rent, 2);
        $this->save();
    }
}
