<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HipTimeSyncLog extends Model
{
    protected $table = 'hiptime_sync_logs';

    protected $fillable = [
        'received', 'created', 'skipped',
        'unmapped_enroll_numbers', 'unmapped_ids', 'errors', 'message', 'ip',
    ];

    protected $casts = [
        'unmapped_enroll_numbers' => 'array',
        'unmapped_ids' => 'array',
        'errors' => 'array',
    ];
}
