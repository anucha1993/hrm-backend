<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class EmployeeDocument extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'name',
        'file_path',
        'original_name',
        'mime_type',
        'size',
    ];

    protected $appends = ['url'];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function getUrlAttribute(): ?string
    {
        return $this->file_path ? Storage::url($this->file_path) : null;
    }
}
