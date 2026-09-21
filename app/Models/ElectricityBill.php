<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ElectricityBill extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_FINALIZED = 'finalized';

    protected $fillable = [
        'code', 'bill_month', 'rate_per_unit', 'status', 'note', 'created_by', 'finalized_at',
    ];

    protected $casts = [
        'bill_month' => 'date',
        'rate_per_unit' => 'decimal:2',
        'finalized_at' => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(ElectricityBillItem::class)->orderBy('order');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public static function generateCode(\DateTimeInterface $month): string
    {
        $prefix = 'ELEC-' . $month->format('Y-m');
        $last = static::where('code', 'like', $prefix . '%')->orderByDesc('code')->value('code');
        if (! $last) {
            return $prefix;
        }
        // เดือนเดียวกันถูกสร้างซ้ำ (แก้ไขใหม่/สร้างใหม่หลังลบ) — ต่อท้าย -2, -3, ...
        if (preg_match('/-(\d+)$/', $last, $m)) {
            return $prefix . '-' . ((int) $m[1] + 1);
        }
        return $prefix . '-2';
    }
}
