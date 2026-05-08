<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OtSession extends Model
{
    protected $fillable = [
        'ot_date', 'start_time', 'end_time',
        'ot_type', 'rate_mode', 'hourly_amount', 'multiplier',
        'description', 'status', 'created_by',
    ];

    protected $casts = [
        'ot_date' => 'date',
        'hourly_amount' => 'decimal:2',
        'multiplier' => 'decimal:2',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function employees(): HasMany
    {
        return $this->hasMany(OtSessionEmployee::class);
    }
}
