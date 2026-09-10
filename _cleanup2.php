<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;

foreach (['test_hrmember2@cyc-hrm.local', 'test_realhr@cyc-hrm.local'] as $email) {
    $u = User::where('email', $email)->first();
    if ($u) { $u->tokens()->delete(); $u->delete(); echo "Deleted {$email}\n"; }
}
