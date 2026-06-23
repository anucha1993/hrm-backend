<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Employee extends Model
{
    use HasFactory, SoftDeletes;

    public const STATUS_ACTIVE     = 'active';
    public const STATUS_RESIGNED   = 'resigned';
    public const STATUS_TERMINATED = 'terminated';
    public const STATUS_SUSPENDED  = 'suspended';

    protected $fillable = [
        'employee_code',
        'title',
        'first_name',
        'last_name',
        'nickname',
        'birth_date',
        'gender',
        'phone',
        'email',
        'address',
        'national_id',
        'labour_id',
        'marital_status',
        'religion',
        'education_level',
        'country_id',
        'department_id',
        'work_profile_id',
        'employment_type_id',
        'position',
        'hire_date',
        'resign_date',
        'base_salary',
        'bank_name',
        'bank_account_no',
        'bank_account_name',
        'emergency_contact_name',
        'emergency_contact_relation',
        'emergency_contact_phone',
        'status',
        'avatar_path',
        'note',
        'user_id',
    ];

    protected $casts = [
        'birth_date'  => 'date',
        'hire_date'   => 'date',
        'resign_date' => 'date',
        'base_salary' => 'decimal:2',
        'labour_id'   => 'integer',
    ];

    protected $appends = ['full_name', 'age'];

    public function getFullNameAttribute(): string
    {
        return trim("{$this->title}{$this->first_name} {$this->last_name}");
    }

    public function getAgeAttribute(): ?int
    {
        if (! $this->birth_date) return null;
        return Carbon::parse($this->birth_date)->age;
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function workProfile(): BelongsTo
    {
        return $this->belongsTo(WorkProfile::class);
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function employmentType(): BelongsTo
    {
        return $this->belongsTo(EmploymentType::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(EmployeeDocument::class);
    }
}
