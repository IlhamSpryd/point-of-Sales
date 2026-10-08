<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

$dbName = DB::connection()->getDatabaseName();
echo 'Connected DB: '.$dbName."\n";

$facts = [];
$facts['VERSION()'] = DB::selectOne('SELECT VERSION() as v')->v;
$facts['@@version_comment'] = DB::selectOne('SELECT @@version_comment as v')->v;
$facts['@@sql_mode'] = DB::selectOne('SELECT @@sql_mode as v')->v;
$facts['@@transaction_isolation'] = DB::selectOne('SELECT @@transaction_isolation as v')->v ?? DB::selectOne('SELECT @@tx_isolation as v')->v ?? 'Unknown';
$facts['@@log_bin_trust_function_creators'] = DB::selectOne('SELECT @@log_bin_trust_function_creators as v')->v;
$facts['@@innodb_lock_wait_timeout'] = DB::selectOne('SELECT @@innodb_lock_wait_timeout as v')->v;

$dbCharCol = DB::selectOne('SELECT default_character_set_name, default_collation_name FROM information_schema.SCHEMATA WHERE schema_name = ?', [$dbName]);
$facts['charset'] = $dbCharCol->default_character_set_name;
$facts['collation'] = $dbCharCol->default_collation_name;

echo "--- ENGINE FACTS ---\n";
print_r($facts);
echo "--------------------\n";

$auditDir = storage_path('app/private/audit-baseline');
if (! File::exists($auditDir)) {
    File::makeDirectory($auditDir, 0755, true);
}

// Get all tables
$tables = DB::select('SHOW TABLES');
$tableKey = 'Tables_in_'.$dbName;
$schemaData = [];
$rowCount = [];
$triggers = [];
$checks = [];
$fks = [];
$generated = [];

foreach ($tables as $t) {
    $tableName = $t->$tableKey;

    // Show create table
    $create = DB::selectOne("SHOW CREATE TABLE `{$tableName}`");
    $createKey = 'Create Table';
    if (! isset($create->$createKey)) {
        $createKey = 'Create View'; // for views if any
    }
    $schemaData[$tableName] = $create->$createKey ?? 'Unknown';

    // Row count
    $count = DB::selectOne("SELECT COUNT(*) as c FROM `{$tableName}`")->c;
    $rowCount[$tableName] = $count;

    // Triggers
    $trigs = DB::select("SHOW TRIGGERS LIKE '{$tableName}'");
    $triggers[$tableName] = $trigs;

    // Checks (from information_schema)
    $tableChecks = DB::select('SELECT CONSTRAINT_NAME, CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = ? AND TABLE_NAME = ?', [$dbName, $tableName]);
    $checks[$tableName] = $tableChecks;

    // FKs
    $tableFks = DB::select('
        SELECT kcu.CONSTRAINT_NAME, kcu.COLUMN_NAME, kcu.REFERENCED_TABLE_NAME, kcu.REFERENCED_COLUMN_NAME, rc.DELETE_RULE 
        FROM information_schema.KEY_COLUMN_USAGE kcu
        JOIN information_schema.REFERENTIAL_CONSTRAINTS rc 
        ON kcu.CONSTRAINT_NAME = rc.CONSTRAINT_NAME AND kcu.CONSTRAINT_SCHEMA = rc.CONSTRAINT_SCHEMA
        WHERE kcu.TABLE_NAME = ? AND kcu.CONSTRAINT_SCHEMA = ? AND kcu.REFERENCED_TABLE_NAME IS NOT NULL
    ', [$tableName, $dbName]);
    $fks[$tableName] = $tableFks;

    // Generated columns
    $genCols = DB::select("SELECT COLUMN_NAME, GENERATION_EXPRESSION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND EXTRA LIKE '%GENERATED%'", [$dbName, $tableName]);
    $generated[$tableName] = $genCols;
}

File::put($auditDir.'/schema.txt', print_r($schemaData, true));
File::put($auditDir.'/row_counts.txt', print_r($rowCount, true));
File::put($auditDir.'/triggers.txt', print_r($triggers, true));
File::put($auditDir.'/checks.txt', print_r($checks, true));
File::put($auditDir.'/fks.txt', print_r($fks, true));
File::put($auditDir.'/generated_columns.txt', print_r($generated, true));

echo "Audit baseline saved to $auditDir\n";

// Specific Questions
echo "\n--- SPECIFIC QUESTIONS ---\n";
echo "1. CHECK constraints on payments.status:\n";
print_r($checks['payments'] ?? 'None');

echo "\n2. Does activity_logs have user_agent and metadata?\n";
$cols = DB::select('SHOW COLUMNS FROM activity_logs');
$colNames = array_map(fn ($c) => $c->Field, $cols);
echo 'Columns: '.implode(', ', $colNames)."\n";
echo 'Has user_agent: '.(in_array('user_agent', $colNames) ? 'Yes' : 'No')."\n";
echo 'Has metadata: '.(in_array('metadata', $colNames) ? 'Yes' : 'No')."\n";

echo "\n3. Counts of tenants, stores, users:\n";
echo 'Tenants: '.($rowCount['tenants'] ?? 'N/A')."\n";
echo 'Stores: '.($rowCount['stores'] ?? 'N/A')."\n";
echo 'Users: '.($rowCount['users'] ?? 'N/A')."\n";

echo "\n4. Table-by-table tenant_id/store_id rows:\n";
foreach ($tables as $t) {
    $tableName = $t->$tableKey;
    $tcols = DB::select("SHOW COLUMNS FROM `{$tableName}`");
    $tcolNames = array_map(fn ($c) => $c->Field, $tcols);

    if (in_array('tenant_id', $tcolNames)) {
        $nullCount = DB::selectOne("SELECT COUNT(*) as c FROM `{$tableName}` WHERE tenant_id IS NULL")->c;
        $oneCount = DB::selectOne("SELECT COUNT(*) as c FROM `{$tableName}` WHERE tenant_id = 1")->c;
        echo "$tableName tenant_id: NULL=$nullCount, 1=$oneCount\n";
    }
    if (in_array('store_id', $tcolNames)) {
        $nullCount = DB::selectOne("SELECT COUNT(*) as c FROM `{$tableName}` WHERE store_id IS NULL")->c;
        $oneCount = DB::selectOne("SELECT COUNT(*) as c FROM `{$tableName}` WHERE store_id = 1")->c;
        echo "$tableName store_id: NULL=$nullCount, 1=$oneCount\n";
    }
}

echo "\n5. Default values on tenant_id/store_id columns:\n";
foreach ($tables as $t) {
    $tableName = $t->$tableKey;
    $tcols = DB::select("SHOW COLUMNS FROM `{$tableName}`");
    foreach ($tcols as $col) {
        if (in_array($col->Field, ['tenant_id', 'store_id'])) {
            echo "$tableName.{$col->Field} DEFAULT: ".($col->Default ?? 'NULL/None')."\n";
        }
    }
}
