<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$table = App\Models\Table::first();
echo "Before edit: " . $table->secure_token . "\n";
echo "QR URL Before: " . route('customer.menu.index', ['token' => $table->secure_token], false) . "\n";

$table->update(['capacity' => 12]);

$table->refresh();
echo "After edit: " . $table->secure_token . "\n";
echo "QR URL After: " . route('customer.menu.index', ['token' => $table->secure_token], false) . "\n";

