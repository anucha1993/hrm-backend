<?php
$base = 'http://127.0.0.1:8100/api';
$hrMemberToken = '122|ctE2GiEXMM20m2I9emnalYFWHjr9A91llGM4lbSQ2bf07852';
$realHrToken = '123|BxnAeHhiWXx5bX3W6WC00S5OIEvZkUzHi9QddrwI54542741';
$woId = 1;

function call($url, $token) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer {$token}", "Accept: application/json"]);
    $res = curl_exec($ch);
    curl_close($ch);
    return json_decode($res, true);
}

$showHr = call("{$base}/payroll/work-orders/{$woId}", $hrMemberToken);
echo "[HrMember] show total_amount: " . var_export($showHr['data']['total_amount'] ?? 'MISSING_KEY', true) . " (expect null)\n";
if (!empty($showHr['data']['items'])) {
    echo "[HrMember] item[0] rate_used: " . var_export($showHr['data']['items'][0]['rate_used'] ?? 'MISSING', true) . " total_amount: " . var_export($showHr['data']['items'][0]['total_amount'] ?? 'MISSING', true) . "\n";
    echo "[HrMember] item[0] target_qty (should stay visible): " . var_export($showHr['data']['items'][0]['target_qty'] ?? 'MISSING', true) . "\n";
}

$showRealHr = call("{$base}/payroll/work-orders/{$woId}", $realHrToken);
echo "\n[RealHR] show total_amount: " . var_export($showRealHr['data']['total_amount'] ?? 'MISSING_KEY', true) . " (expect real value, no regression)\n";

$indexHr = call("{$base}/payroll/work-orders?per_page=5", $hrMemberToken);
$firstRow = $indexHr['data']['data'][0] ?? null;
echo "\n[HrMember] index first row total_amount: " . var_export($firstRow['total_amount'] ?? 'MISSING', true) . " (expect null)\n";
