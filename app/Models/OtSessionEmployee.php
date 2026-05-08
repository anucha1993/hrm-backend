<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OtSessionEmployee extends Model
{
    protected $table = 'ot_session_employees';

    protected $fillable = [
        'ot_session_id', 'employee_id',
        'hours', 'hourly_rate_snapshot', 'total_amount',
        'note', 'payroll_slip_id',
    ];

    protected $casts = [
        'hours' => 'decimal:2',
        'hourly_rate_snapshot' => 'decimal:2',
        'total_amount' => 'decimal:2',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(OtSession::class, 'ot_session_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function slip(): BelongsTo
    {
        return $this->belongsTo(PayrollSlip::class, 'payroll_slip_id');
    }
}
