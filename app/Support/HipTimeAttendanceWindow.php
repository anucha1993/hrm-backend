<?php

namespace App\Support;

use App\Models\PayrollSetting;
use Carbon\Carbon;

/**
 * เครื่องสแกน HIP Time อาจถูกใช้เปิดประตูด้วย ทำให้มีหลายรอบสแกนต่อวัน
 * จึงจัดประเภทเข้างาน/ออกงานจากเวลาที่สแกน (ไม่ใช่ timetype ดิบจากเครื่อง) แล้วใช้จุดเดียวกันนี้
 * ทั้งตอน sync (กำหนด type) และตอนแสดงผลที่ /attendance/manage (เลือก record ที่ดีที่สุดต่อวันมาโชว์)
 *
 * ช่วงเวลาตั้งค่าได้ที่หน้า "ข้อมูลบริษัท" (เก็บใน payroll_settings คีย์ hiptime_checkin_window_start/end)
 */
final class HipTimeAttendanceWindow
{
    private const DEFAULT_START = '04:00';
    private const DEFAULT_END = '10:00';

    private static ?string $cachedStart = null;
    private static ?string $cachedEnd = null;

    public static function checkInStart(): string
    {
        return self::$cachedStart ??= self::normalizeTime(PayrollSetting::get('hiptime_checkin_window_start', self::DEFAULT_START));
    }

    public static function checkInEnd(): string
    {
        return self::$cachedEnd ??= self::normalizeTime(PayrollSetting::get('hiptime_checkin_window_end', self::DEFAULT_END));
    }

    /** @return array{0: string, 1: string} [type, work_date] จากเวลาท้องถิ่น (ต้องแปลง tz มาก่อนเรียก) */
    public static function classify(Carbon $localTime): array
    {
        $time = $localTime->format('H:i:s');

        if ($time >= self::checkInStart() && $time < self::checkInEnd()) {
            return ['check_in', $localTime->format('Y-m-d')];
        }

        return ['check_out', self::workDateFor('check_out', $localTime)];
    }

    /** วันที่ใช้จัดกลุ่ม record เมื่อรู้ type อยู่แล้ว (ช่วงออกงานที่ตกดึกข้ามเที่ยงคืนนับเป็นของวันก่อนหน้า) */
    public static function workDateFor(string $type, Carbon $localTime): string
    {
        if ($type === 'check_in') {
            return $localTime->format('Y-m-d');
        }

        $time = $localTime->format('H:i:s');

        return $time < self::checkInStart()
            ? $localTime->copy()->subDay()->format('Y-m-d')
            : $localTime->format('Y-m-d');
    }

    /**
     * จาก scan ทั้งหมดของพนักงานคนเดียวในวันทำงานเดียว (เรียงเวลาน้อย->มากแล้ว) หาว่า index ไหนคือเข้างาน/ออกงาน
     * - scan ที่อยู่ในช่วงเข้างาน (checkInStart-checkInEnd) ถือเป็นเข้างานเสมอ (เอาอันแรกสุดที่อยู่ในช่วง)
     * - ถ้าไม่มี scan อยู่ในช่วงเลย (มาสายเกินช่วงเข้างาน) แต่มี scan มากกว่า 1 ครั้ง และสแกนแรกกับสแกนสุดท้ายห่างกันเกิน 2 ชม.
     *   ให้ถือว่าสแกนแรกคือเข้างาน (มาสายมาก ไม่ใช่แค่เปิดประตูสั้นๆ)
     * - ออกงาน = สแกนล่าสุดของวันเสมอ ยกเว้นเหลือสแกนเดียวที่ถูกใช้เป็นเข้างานไปแล้ว (ยังไม่ออกงาน)
     * @param Carbon[] $localTimes เรียงจากน้อยไปมากแล้ว
     * @return array{check_in: ?int, check_out: ?int}
     */
    public static function classifyGroup(array $localTimes): array
    {
        $n = count($localTimes);
        if ($n === 0) {
            return ['check_in' => null, 'check_out' => null];
        }

        $checkInIdx = null;
        foreach ($localTimes as $i => $t) {
            $time = $t->format('H:i:s');
            if ($time >= self::checkInStart() && $time < self::checkInEnd()) {
                $checkInIdx = $i;
                break;
            }
        }

        if ($checkInIdx === null && $n > 1 && $localTimes[0]->diffInMinutes($localTimes[$n - 1]) > 120) {
            $checkInIdx = 0;
        }

        $checkOutIdx = $n - 1;
        if ($checkOutIdx === $checkInIdx) {
            $checkOutIdx = null;
        }

        return ['check_in' => $checkInIdx, 'check_out' => $checkOutIdx];
    }

    /** ค่าจากหน้า settings เป็น "HH:MM" แต่เทียบกับ Carbon::format('H:i:s') ต้องมี ":ss" ครบ */
    private static function normalizeTime(mixed $value): string
    {
        $v = trim((string) $value);
        return preg_match('/^\d{2}:\d{2}$/', $v) ? $v . ':00' : $v;
    }
}

