<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\DormRoom;

foreach (DormRoom::all(['id', 'room_no', 'is_active']) as $r) {
    echo "{$r->id} | {$r->room_no} | active=" . ($r->is_active ? 1 : 0) . "\n";
}
echo "total: " . DormRoom::count() . "\n";
