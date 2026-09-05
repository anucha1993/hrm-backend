<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\TigerVoucher\TigerVoucherService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TigerVoucherSettingController extends Controller
{
    public function __construct(protected TigerVoucherService $service) {}

    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->service->getPublicSettings()]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'base_url' => ['required', 'url', 'max:255'],
            'username' => ['required', 'string', 'max:100'],
            'password' => ['nullable', 'string', 'max:255'],
            'mobile' => ['nullable', 'string', 'max:30'],
        ]);
        $this->service->saveSettings($data, $request->user()->id);
        return response()->json(['data' => $this->service->getPublicSettings(), 'message' => 'บันทึกการตั้งค่าเรียบร้อย']);
    }

    /**
     * ทดสอบเข้าสู่ระบบ TigerPay ด้วย credential ที่บันทึกไว้ (เหมือน request "login" ใน Postman)
     */
    public function testConnection(): JsonResponse
    {
        $result = $this->service->testConnection();
        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'token' => $result['token'] ? substr($result['token'], 0, 8) . '…' : null,
            'http_status' => $result['http_status'] ?? null,
        ]);
    }
}
