<?php

namespace App\Console\Commands;

use App\Models\Country;
use App\Models\Employee;
use App\Services\LabourApiService;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;

class LinkEmployeesToLabour extends Command
{
    protected $signature = 'employees:link-labour
        {--dry-run : แสดงผลโดยไม่บันทึก}
        {--all : ลองค้นหาทุกคนที่มีเลขบัตร (ไม่จำกัดเฉพาะที่มีตัวอักษร)}
        {--relink : ค้นหาใหม่แม้คนที่มี labour_id อยู่แล้ว}
        {--sleep=1100 : หน่วงเวลาระหว่างการเรียก API (มิลลิวินาที) กัน rate limit}';

    protected $description = 'เชื่อมพนักงานต่างด้าวกับระบบ Labour โดยค้นหา labour_id + สัญชาติ จากเลขพาสปอร์ต (national_id)';

    /** แปลงค่าสัญชาติจาก Labour API (เช่น "myanmar" หรือ "MM") → รหัสประเทศในระบบ */
    private const NATIONALITY_TO_CODE = [
        'mm' => 'MM', 'myanmar' => 'MM', 'burmese' => 'MM', 'burma' => 'MM',
        'kh' => 'KH', 'cambodia' => 'KH', 'cambodian' => 'KH', 'khmer' => 'KH',
        'la' => 'LA', 'laos' => 'LA', 'lao' => 'LA', 'laotian' => 'LA',
        'th' => 'TH', 'thai' => 'TH', 'thailand' => 'TH',
    ];

    public function handle(LabourApiService $labour): int
    {
        $dry     = (bool) $this->option('dry-run');
        $all     = (bool) $this->option('all');
        $relink  = (bool) $this->option('relink');
        $sleepMs = max(0, (int) $this->option('sleep'));

        // map รหัสประเทศ → country_id
        $countryMap = Country::pluck('id', 'code')->all();

        $query = Employee::query()->whereNotNull('national_id');

        if (! $relink) {
            $query->whereNull('labour_id');
        }

        if (! $all) {
            // เฉพาะเลขบัตรที่มีตัวอักษร (น่าจะเป็นเลขพาสปอร์ตของต่างด้าว)
            $query->whereRaw("national_id REGEXP '[A-Za-z]'");
        }

        $targets = $query->orderBy('id')->get();
        $this->info("เป้าหมายที่จะค้นหา: {$targets->count()} คน" . ($dry ? ' [DRY-RUN]' : ''));

        if ($targets->isEmpty()) {
            $this->warn('ไม่มีพนักงานที่ตรงเงื่อนไข');
            return self::SUCCESS;
        }

        $matched   = 0;
        $notFound   = 0;
        $errors     = 0;

        foreach ($targets as $emp) {
            $passport = trim((string) $emp->national_id);
            if ($passport === '') {
                continue;
            }

            try {
                $res = $this->lookup($labour, $passport, $sleepMs);
            } catch (ConnectionException $e) {
                $this->error("เชื่อมต่อ Labour API ไม่ได้: {$e->getMessage()}");
                $this->error('หยุดการทำงาน — โปรดตรวจสอบการเชื่อมต่อ/คอนฟิก แล้วลองใหม่');
                return self::FAILURE;
            } catch (\Throwable $e) {
                $errors++;
                $this->warn("- #{$emp->id} {$emp->employee_code} ({$passport}): ผิดพลาด " . $this->shortError($e));
                continue;
            }

            $labourId = $res['data']['labour_id'] ?? null;

            if (($res['_http_status'] ?? null) === 404 || $labourId === null) {
                $notFound++;
                $this->line("- #{$emp->id} {$emp->employee_code} ({$passport}): ไม่พบในระบบ Labour");
                continue;
            }

            $update = ['labour_id' => (int) $labourId];

            // เติม country_id จากสัญชาติของ labour (รองรับทั้ง "myanmar" และ "MM")
            $code = $this->resolveCountryCode($res['data']['labour_nationality'] ?? null, $countryMap);
            $countryNote = '';
            if ($code !== null && isset($countryMap[$code])) {
                $update['country_id'] = $countryMap[$code];
                $countryNote = " [{$code}]";
            }

            if (! $dry) {
                $emp->update($update);
            }

            $matched++;
            $name = $res['data']['labour_fullname_th'] ?? $res['data']['labour_fullname'] ?? '';
            $this->info("✓ #{$emp->id} {$emp->employee_code} ({$passport}) → labour_id={$labourId}{$countryNote} {$name}");
        }

        $this->newLine();
        $this->info("สรุป: เชื่อมสำเร็จ {$matched} คน, ไม่พบ {$notFound} คน, ผิดพลาด {$errors} คน"
            . ($dry ? ' [DRY-RUN ไม่ได้บันทึก]' : ''));

        return self::SUCCESS;
    }

    /**
     * เรียก Labour API พร้อม retry เมื่อเจอ 429 (Too Many Attempts) และหน่วงเวลากัน rate limit
     */
    private function lookup(LabourApiService $labour, string $passport, int $sleepMs): array
    {
        $attempt = 0;

        while (true) {
            $attempt++;
            try {
                $res = $labour->getLabourByPassport($passport);
                if ($sleepMs > 0) {
                    usleep($sleepMs * 1000);
                }
                return $res;
            } catch (RequestException $e) {
                $status = $e->response?->status();
                if ($status === 429 && $attempt <= 4) {
                    $wait = (int) ($e->response?->header('Retry-After') ?: 5);
                    $wait = min(max($wait, 3), 30);
                    $this->warn("  ⏳ ติด rate limit — รอ {$wait} วินาที แล้วลองใหม่ (ครั้งที่ {$attempt})");
                    sleep($wait);
                    continue;
                }
                throw $e;
            }
        }
    }

    private function resolveCountryCode(?string $nationality, array $countryMap): ?string
    {
        if ($nationality === null) {
            return null;
        }
        $key = strtolower(trim($nationality));
        if ($key === '') {
            return null;
        }
        if (isset(self::NATIONALITY_TO_CODE[$key])) {
            return self::NATIONALITY_TO_CODE[$key];
        }
        // เผื่อกรณีส่งมาเป็น code อยู่แล้ว (เช่น "MM")
        $upper = strtoupper($key);
        return isset($countryMap[$upper]) ? $upper : null;
    }

    private function shortError(\Throwable $e): string
    {
        $msg = $e->getMessage();
        return strlen($msg) > 120 ? substr($msg, 0, 120) . '…' : $msg;
    }
}
