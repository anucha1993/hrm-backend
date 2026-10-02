<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * กันลงข้อมูลย้อนหลัง — ผู้ที่ไม่มีสิทธิ์ {module}.backdate เลือกวันที่ก่อนวันนี้ไม่ได้
 * (วันนี้คิดตามเวลาไทย เพราะ app timezone เป็น UTC)
 */
trait GuardsBackdate
{
    protected function denyBackdate(Request $request, ?string $date, string $field, string $permission): void
    {
        if (! $date || $request->user()?->hasPermission($permission)) {
            return;
        }
        $today = now('Asia/Bangkok');
        if (substr($date, 0, 10) < $today->toDateString()) {
            throw ValidationException::withMessages([
                $field => 'ไม่มีสิทธิ์ลงย้อนหลัง — เลือกวันที่ได้ตั้งแต่วันนี้ (' . $today->format('d/m/Y') . ') เป็นต้นไป',
            ]);
        }
    }
}
