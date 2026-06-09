<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GoodsDepositItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'deposit_slip_id', 'item_name', 'qty', 'unit_price', 'amount', 'note', 'order',
    ];

    protected $casts = [
        'qty'        => 'decimal:2',
        'unit_price' => 'decimal:2',
        'amount'     => 'decimal:2',
    ];

    public function slip(): BelongsTo
    {
        return $this->belongsTo(GoodsDepositSlip::class, 'deposit_slip_id');
    }
}
