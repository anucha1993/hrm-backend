<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Task extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'code', 'title', 'description', 'priority', 'due_date',
        'status', 'location_name', 'note', 'created_by',
    ];

    protected $casts = [
        'due_date' => 'date:Y-m-d',
    ];

    public function assignees(): HasMany
    {
        return $this->hasMany(TaskAssignee::class)->orderBy('id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public static function generateCode(): string
    {
        $prefix = 'TSK-' . now()->format('ymd');
        $last = static::where('code', 'like', $prefix . '%')->orderBy('id', 'desc')->first();
        $next = 1;
        if ($last) {
            $next = ((int) substr($last->code, -3)) + 1;
        }
        return $prefix . str_pad((string) $next, 3, '0', STR_PAD_LEFT);
    }

    /**
     * คำนวณสถานะใหม่จาก assignee statuses
     */
    public function refreshStatusFromAssignees(): void
    {
        $statuses = $this->assignees()->pluck('status')->all();
        if (empty($statuses)) return;

        $all = fn ($s) => count(array_filter($statuses, fn ($x) => $x === $s)) === count($statuses);

        if ($all('approved')) {
            $this->status = 'completed';
        } elseif (in_array('submitted', $statuses) || in_array('approved', $statuses)) {
            $this->status = 'submitted';
        } elseif (in_array('in_progress', $statuses)) {
            $this->status = 'in_progress';
        } else {
            $this->status = 'open';
        }
        $this->save();
    }
}
