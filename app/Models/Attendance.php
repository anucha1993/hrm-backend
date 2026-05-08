<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class Attendance extends Model
{
    protected $fillable = [
        'employee_id', 'type', 'checked_at',
        'latitude', 'longitude', 'accuracy_m',
        'office_location_id', 'distance_m', 'outside_geofence',
        'work_shift_id', 'status', 'late_minutes',
        'photo_path', 'note',
        'source', 'is_edited', 'edited_by', 'edited_at', 'edit_reason',
    ];

    protected $casts = [
        'checked_at'       => 'datetime',
        'latitude'         => 'float',
        'longitude'        => 'float',
        'accuracy_m'       => 'float',
        'distance_m'       => 'float',
        'outside_geofence' => 'boolean',
        'late_minutes'     => 'integer',
        'is_edited'        => 'boolean',
        'edited_at'        => 'datetime',
    ];

    protected $appends = ['photo_url'];

    public function getPhotoUrlAttribute(): ?string
    {
        return $this->photo_path ? asset(Storage::url($this->photo_path)) : null;
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function officeLocation(): BelongsTo
    {
        return $this->belongsTo(OfficeLocation::class);
    }

    public function workShift(): BelongsTo
    {
        return $this->belongsTo(WorkShift::class);
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'edited_by');
    }

    public function auditLogs()
    {
        return $this->hasMany(AttendanceAuditLog::class);
    }
}
