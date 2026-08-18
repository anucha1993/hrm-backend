<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeRelative extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'relative_employee_id',
        'relation',
        'note',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function relative(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'relative_employee_id');
    }
}
