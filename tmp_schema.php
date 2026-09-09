<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

$tables = ['categories', 'products', 'orders', 'order_details', 'users', 'roles'];
$schema = [];
foreach ($tables as $table) {
    if (Schema::hasTable($table)) {
        $schema[$table] = DB::select("SHOW COLUMNS FROM $table");
    }
}
echo json_encode($schema, JSON_PRETTY_PRINT);
