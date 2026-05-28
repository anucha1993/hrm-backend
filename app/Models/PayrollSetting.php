<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PayrollSetting extends Model
{
    use HasFactory;

    protected $fillable = ['key', 'value', 'category', 'label', 'description', 'updated_by'];

    protected $casts = [
        'value' => 'array',
    ];

    public static function get(string $key, mixed $default = null): mixed
    {
        $row = static::where('key', $key)->first();
        return $row ? $row->value : $default;
    }

    public static function set(string $key, mixed $value, ?int $userId = null, string $category = 'general', ?string $label = null): self
    {
        return static::updateOrCreate(
            ['key' => $key],
            ['value' => $value, 'updated_by' => $userId, 'category' => $category, 'label' => $label],
        );
    }
}
