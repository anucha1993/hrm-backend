<?php

namespace App\Services\TigerVoucher;

use App\Models\PayrollSetting;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * ไคลเอนต์เรียก TigerPay Voucher API (api.tigercashbox.com) — ตั้งค่า/ทดสอบผ่านหน้า Settings
 * เก็บ credential ใน payroll_settings (category=tiger_voucher), เข้ารหัส password ด้วย Crypt ก่อนบันทึกเสมอ
 */
class TigerVoucherService
{
    private const CATEGORY = 'tiger_voucher';
    private const DEFAULT_BASE_URL = 'https://api.tigercashbox.com';

    /**
     * อ่านค่าที่ตั้งไว้ (ไม่คืน password ดิบ — คืนเฉพาะ has_password)
     */
    public function getPublicSettings(): array
    {
        return [
            'base_url' => PayrollSetting::get('tiger_base_url', self::DEFAULT_BASE_URL),
            'username' => PayrollSetting::get('tiger_username', ''),
            'mobile' => PayrollSetting::get('tiger_mobile', ''),
            'has_password' => (bool) PayrollSetting::get('tiger_password', null),
        ];
    }

    public function saveSettings(array $data, ?int $userId = null): void
    {
        if (array_key_exists('base_url', $data)) {
            PayrollSetting::set('tiger_base_url', $data['base_url'], $userId, self::CATEGORY, 'Tiger Voucher Base URL');
        }
        if (array_key_exists('username', $data)) {
            PayrollSetting::set('tiger_username', $data['username'], $userId, self::CATEGORY, 'Tiger Voucher Username');
        }
        if (array_key_exists('mobile', $data)) {
            PayrollSetting::set('tiger_mobile', $data['mobile'], $userId, self::CATEGORY, 'Tiger Voucher Mobile');
        }
        if (! empty($data['password'])) {
            PayrollSetting::set('tiger_password', Crypt::encryptString($data['password']), $userId, self::CATEGORY, 'Tiger Voucher Password');
        }
    }

    private function baseUrl(): string
    {
        return rtrim((string) PayrollSetting::get('tiger_base_url', self::DEFAULT_BASE_URL), '/');
    }

    private function credentials(): array
    {
        $encrypted = PayrollSetting::get('tiger_password', null);
        $password = null;
        if ($encrypted) {
            try {
                $password = Crypt::decryptString($encrypted);
            } catch (Throwable $e) {
                Log::warning('TigerVoucherService: ไม่สามารถถอดรหัส password ได้ (' . $e->getMessage() . ')');
            }
        }
        return [
            'username' => PayrollSetting::get('tiger_username', ''),
            'password' => $password,
            'mobile' => PayrollSetting::get('tiger_mobile', ''),
        ];
    }

    /**
     * เข้าสู่ระบบ TigerPay ด้วย credential ที่ตั้งไว้ — ใช้ทั้งปุ่ม "ทดสอบการเชื่อมต่อ" และก่อนเรียก voucher API อื่นๆ
     */
    public function login(): array
    {
        $cred = $this->credentials();
        if (empty($cred['username']) || empty($cred['password'])) {
            return ['success' => false, 'message' => 'ยังไม่ได้ตั้งค่า Username/Password ของ TigerPay', 'token' => null];
        }

        try {
            $res = Http::asForm()
                ->timeout(15)
                ->post($this->baseUrl() . '/api/tigerpay/login', [
                    'username' => $cred['username'],
                    'password' => $cred['password'],
                    'mobile' => $cred['mobile'],
                ]);
        } catch (Throwable $e) {
            return ['success' => false, 'message' => 'เชื่อมต่อ TigerPay ไม่สำเร็จ: ' . $e->getMessage(), 'token' => null];
        }

        $json = $res->json() ?? [];
        $token = $json['success']['token'] ?? null;

        return [
            'success' => $res->successful() && $token,
            'message' => $token ? 'เชื่อมต่อสำเร็จ' : ($json['message'] ?? $json['error'] ?? 'เข้าสู่ระบบไม่สำเร็จ'),
            'token' => $token,
            'http_status' => $res->status(),
            'raw' => $json,
        ];
    }

    /**
     * ใช้โดยหน้า Settings ปุ่ม "ทดสอบการเชื่อมต่อ" — ไม่ throw, คืนผลลัพธ์เสมอ
     */
    public function testConnection(): array
    {
        return $this->login();
    }

    public function createVoucher(array $params): array
    {
        $login = $this->login();
        if (! $login['success']) {
            return ['success' => false, 'message' => $login['message'], 'data' => null];
        }

        try {
            $res = Http::asForm()
                ->timeout(15)
                ->withToken($login['token'])
                ->post($this->baseUrl() . '/api/voucher/create', [
                    'amount' => $params['amount'],
                    'number_of_voucher' => $params['number_of_voucher'] ?? 1,
                    'start_date' => $params['start_date'],
                    'expire_date' => $params['expire_date'],
                    'note' => $params['note'] ?? '',
                    'authen_required' => $params['authen_required'] ?? 0,
                    'ref_num' => $params['ref_num'] ?? (string) Str::uuid(),
                    'category' => $params['category'] ?? 'Advance',
                ]);
        } catch (Throwable $e) {
            return ['success' => false, 'message' => 'เรียก TigerPay voucher/create ไม่สำเร็จ: ' . $e->getMessage(), 'data' => null];
        }

        $json = $res->json() ?? [];
        $ok = $res->successful() && $this->isTruthy($json['success'] ?? null);
        return [
            'success' => $ok,
            'message' => $ok ? null : ($json['message'] ?? 'สร้าง voucher ไม่สำเร็จ'),
            // หมายเหตุ: voucher/create คืน success เป็นสตริง "true"/"false" (ไม่ใช่ object เหมือน login) เลข voucher อยู่ที่ result[0]
            'data' => $json,
            'http_status' => $res->status(),
            'raw' => $json,
        ];
    }

    public function showVoucher(string $code): array
    {
        $login = $this->login();
        try {
            $res = Http::timeout(15)
                ->when($login['success'], fn ($r) => $r->withToken($login['token']))
                ->get($this->baseUrl() . '/api/voucher/show/' . urlencode($code));
        } catch (Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage(), 'data' => null];
        }
        $json = $res->json() ?? [];
        return ['success' => $res->successful(), 'data' => $json, 'raw' => $json];
    }

    public function cancelVoucher(string $code): array
    {
        $login = $this->login();
        if (! $login['success']) {
            return ['success' => false, 'message' => $login['message'], 'data' => null];
        }
        try {
            $res = Http::timeout(15)
                ->withToken($login['token'])
                ->get($this->baseUrl() . '/api/voucher/cancel/' . urlencode($code));
        } catch (Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage(), 'data' => null];
        }
        $json = $res->json() ?? [];
        return ['success' => $res->successful(), 'data' => $json, 'raw' => $json];
    }

    /**
     * TigerPay ใช้ค่า success ไม่เหมือนกันทุก endpoint (login คืน object, create คืนสตริง "true"/"false") — helper นี้ครอบคลุมทั้ง 2 แบบ
     */
    private function isTruthy(mixed $value): bool
    {
        if (is_array($value)) {
            return ! empty($value);
        }
        return in_array($value, [true, 1, '1', 'true', 'TRUE'], true);
    }
}
