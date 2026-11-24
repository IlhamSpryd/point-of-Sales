<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Context\TenantContext;
use App\Modules\Organization\Models\Tenant;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\DefaultPostingRulesSeeder;
use Illuminate\Console\Command;

class SyncPostingRulesCommand extends Command
{
    protected $signature = 'finance:sync-posting-rules
                            {tenant? : Filter tenant ID tertentu}
                            {--tenant= : Filter tenant ID tertentu}';

    protected $description = 'Menyinkronkan COA dan aturan posting (pos.order.paid, pos.order.voided, sales.return.refunded) secara idempoten untuk seluruh tenant';

    public function handle(
        TenantContext $tenantContext,
        ChartOfAccountsSeeder $coaSeeder,
        DefaultPostingRulesSeeder $postingRulesSeeder
    ): int {
        $tenantInput = $this->option('tenant') ?: $this->argument('tenant');

        $tenants = $tenantInput
            ? Tenant::where('id', (int) $tenantInput)->get()
            : Tenant::all();

        if ($tenants->isEmpty()) {
            $this->warn('Tidak ada tenant yang ditemukan untuk disinkronkan.');

            return self::SUCCESS;
        }

        $this->info("Menyinkronkan COA dan Aturan Posting untuk {$tenants->count()} tenant...");

        foreach ($tenants as $tenant) {
            $tenantContext->setTenantId($tenant->id);
            $this->line("-> Menyinkronkan Tenant #{$tenant->id} [{$tenant->name}]...");

            // 1. Sinkronkan COA (idempoten via updateOrCreate pada ChartOfAccountsSeeder)
            $coaSeeder->seedForTenant($tenant->id);

            // 2. Sinkronkan Aturan Posting & Baris Aturan (idempoten via firstOrCreate pada DefaultPostingRulesSeeder)
            $postingRulesSeeder->seedForTenant($tenant->id);
        }

        $this->info('Sinkronisasi COA dan aturan posting berhasil diselesaikan secara idempoten.');

        return self::SUCCESS;
    }
}
