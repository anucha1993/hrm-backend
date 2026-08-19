<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class TaskItemPhoto extends Model
{
    use HasFactory;

    protected $fillable = ['task_item_id', 'kind', 'photo_path', 'employee_id'];

    protected $appends = ['photo_url'];

    public function item(): BelongsTo
    {
        return $this->belongsTo(TaskItem::class, 'task_item_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function getPhotoUrlAttribute(): ?string
    {
        return $this->photo_path ? Storage::disk('public')->url($this->photo_path) : null;
    }
}
