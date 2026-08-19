<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskAssignee extends Model
{
    use HasFactory;

    protected $fillable = [
        'task_id', 'employee_id', 'status',
        'before_photo_path', 'after_photo_path',
        'started_at', 'submitted_at', 'submit_note',
        'rating', 'rating_note', 'rated_at', 'rated_by',
    ];

    protected $casts = [
        'started_at'   => 'datetime',
        'submitted_at' => 'datetime',
        'rated_at'     => 'datetime',
        'rating'       => 'integer',
    ];

    protected $appends = ['before_photo_url', 'after_photo_url'];

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function rater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rated_by');
    }

    public function getBeforePhotoUrlAttribute(): ?string
    {
        return $this->before_photo_path
            ? \Illuminate\Support\Facades\Storage::disk('public')->url($this->before_photo_path)
            : null;
    }

    public function getAfterPhotoUrlAttribute(): ?string
    {
        return $this->after_photo_path
            ? \Illuminate\Support\Facades\Storage::disk('public')->url($this->after_photo_path)
            : null;
    }
}
