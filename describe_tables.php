<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$tables = ['categories', 'products', 'roles', 'users', 'orders', 'order_details'];
foreach ($tables as $table) {
    echo "TABLE: $table\n";
    $columns = DB::select("DESCRIBE $table");
    foreach ($columns as $column) {
        echo "- {$column->Field} ({$column->Type})\n";
    }
    echo "\n";
}
