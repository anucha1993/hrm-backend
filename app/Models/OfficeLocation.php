<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OfficeLocation extends Model
{
    protected $fillable = [
        'name', 'latitude', 'longitude', 'radius_m',
        'enforce_geofence', 'address', 'is_active',
    ];

    protected $casts = [
        'latitude'         => 'float',
        'longitude'        => 'float',
        'radius_m'         => 'integer',
        'enforce_geofence' => 'boolean',
        'is_active'        => 'boolean',
    ];

    /** ระยะห่าง (เมตร) จากพิกัดที่ส่งมา ใช้สูตร Haversine */
    public function distanceFrom(float $lat, float $lng): float
    {
        if ($this->latitude === null || $this->longitude === null) return PHP_FLOAT_MAX;

        $earth = 6371000; // เมตร
        $dLat = deg2rad($lat - $this->latitude);
        $dLng = deg2rad($lng - $this->longitude);
        $a = sin($dLat / 2) ** 2 +
             cos(deg2rad($this->latitude)) * cos(deg2rad($lat)) * sin($dLng / 2) ** 2;
        return 2 * $earth * asin(sqrt($a));
    }
}
