<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$movements = DB::table('stock_movements')
    ->where('business_id', 'BUS-TMOMIA2JW637')
    ->orderBy('created_at')
    ->get();

echo "Total movements: " . $movements->count() . "\n";
foreach ($movements as $m) {
    echo "id={$m->id} created_at={$m->created_at} type={$m->type} qty={$m->qty_change}\n";
}
