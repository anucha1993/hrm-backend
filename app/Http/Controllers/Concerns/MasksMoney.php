<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;

/**
 * ซ่อนตัวเงิน (เรทค่าจ้าง/ค่าจ้างการผลิต) จาก response สำหรับผู้ใช้ที่มีแค่
 * production.view/production.manage (เช่น HrMember) แต่ไม่มีสิทธิ์เงินเดือนจริง
 * (payroll.view / payroll.config) — คงเหลือเฉพาะจำนวน/หน่วยผลิตให้เห็น ไม่เห็นตัวเงิน
 */
trait MasksMoney
{
    protected function maskMoney(array $data, Request $request, array $keys = ['total_amount', 'rate_used', 'rate_at_target_override', 'rate_below_target_override', 'amount']): array
    {
        if ($request->user()?->hasPermission('payroll.view') || $request->user()?->hasPermission('payroll.config')) {
            return $data;
        }

        $walk = function (&$node) use (&$walk, $keys) {
            if (! is_array($node)) return;
            foreach ($node as $k => &$v) {
                if (in_array($k, $keys, true) && ! is_array($v)) {
                    $v = null;
                } elseif (is_array($v)) {
                    $walk($v);
                }
            }
        };
        $walk($data);
        return $data;
    }
}
