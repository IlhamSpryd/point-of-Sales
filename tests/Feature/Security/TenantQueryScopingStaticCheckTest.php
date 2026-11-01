<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

class TenantQueryScopingStaticCheckTest extends TestCase
{
    /**
     * Daftar tabel multi-tenant sensitif yang wajib memiliki filter tenant_id eksplisit.
     *
     * @var list<string>
     */
    protected array $sensitiveTables = [
        'store_credit_accounts',
        'customer_loyalty_accounts',
        'customer_credit_accounts',
        'customer_credit_logs',
        'payroll_records',
        'payroll_batches',
        'payroll_items',
        'product_cost_histories',
        'purchase_requests',
        'purchase_request_lines',
        'promotion_campaigns',
        'promotion_rules',
        'promotion_rewards',
        'promotion_reservations',
        'accounting_payable_outbox',
        'accounting_receivable_outboxes',
    ];

    /**
     * Memindai codebase di direktori app/ untuk memastikan setiap kueri mentah
     * pada tabel multi-tenant sensitif menyertakan klausa tenant_id.
     */
    public function test_raw_database_queries_on_sensitive_tables_must_include_tenant_id_scoping(): void
    {
        $appPath = app_path();
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($appPath));
        $violations = [];
        $queriesChecked = 0;

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $relPath = str_replace(base_path() . DIRECTORY_SEPARATOR, '', $file->getRealPath());

            // Kecualikan offline dataset seeders / generators yang beroperasi di luar runtime transaksi
            if (str_contains($relPath, 'Support' . DIRECTORY_SEPARATOR . 'Dataset') ||
                str_contains($relPath, 'Console' . DIRECTORY_SEPARATOR . 'Commands' . DIRECTORY_SEPARATOR . 'Populate')) {
                continue;
            }

            $content = (string) file_get_contents($file->getRealPath());

            foreach ($this->sensitiveTables as $tableName) {
                // Match pola: DB::table('table_name') hingga akhir statement (titik koma)
                $pattern = "/(DB::table\(\s*['\"]" . preg_quote($tableName, '/') . "['\"]\s*\)(?:(?!;).)*;)/s";
                if (! preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
                    continue;
                }

                foreach ($matches[1] as $match) {
                    $statement = $match[0];
                    $offset = $match[1];
                    $line = substr_count(substr($content, 0, $offset), "\n") + 1;
                    $queriesChecked++;

                    // Pastikan statement menyertakan scoping tenant_id
                    if (! str_contains($statement, 'tenant_id')) {
                        $violations[] = sprintf(
                            '[%s:%d] Unscoped query on multi-tenant table "%s": %s',
                            $relPath,
                            $line,
                            $tableName,
                            trim((string) preg_replace('/\s+/', ' ', substr($statement, 0, 140)))
                        );
                    }
                }
            }
        }

        $this->assertGreaterThan(0, $queriesChecked, 'Sanity check: At least one raw query on sensitive tables was inspected.');
        $this->assertEmpty(
            $violations,
            "Security CI Guard Failure: Found raw queries on multi-tenant tables without tenant_id scoping:\n" .
            implode("\n", $violations)
        );
    }
}
