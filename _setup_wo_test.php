<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Models\Role;
use App\Models\WorkOrder;

$hrRole = Role::where('name', 'hr_member')->first();
$realHr = Role::where('name', 'hr')->first();

$hrMemberUser = User::updateOrCreate(
    ['email' => 'test_hrmember2@cyc-hrm.local'],
    ['name' => 'Test HrMember', 'password' => 'password', 'role_id' => $hrRole->id, 'is_active' => true]
);
$realHrUser = User::updateOrCreate(
    ['email' => 'test_realhr@cyc-hrm.local'],
    ['name' => 'Test Real HR', 'password' => 'password', 'role_id' => $realHr->id, 'is_active' => true]
);

$hrMemberToken = $hrMemberUser->createToken('test')->plainTextToken;
$realHrToken = $realHrUser->createToken('test')->plainTextToken;

$wo = WorkOrder::whereNotNull('total_amount')->where('total_amount', '>', 0)->first();
echo "Test WO id={$wo->id} real total_amount={$wo->total_amount}\n";
echo "HRMEMBER_TOKEN={$hrMemberToken}\n";
echo "REALHR_TOKEN={$realHrToken}\n";
echo "WO_ID={$wo->id}\n";
