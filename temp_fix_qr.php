<?php

use App\Models\Table;
use Illuminate\Support\Str;

$tables = Table::whereNull('secure_token')->orWhere('secure_token', '')->get();
$count = 0;

foreach ($tables as $table) {
    $table->update(['secure_token' => (string) Str::uuid()]);
    $count++;
}

echo "Berhasil mengunci dan menyimpan permanen secure_token untuk $count meja.\n";
