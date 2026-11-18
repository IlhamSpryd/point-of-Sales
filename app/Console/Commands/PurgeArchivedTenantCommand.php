<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Organization\Application\Services\TenantLifecyclePurgeService;
use DomainException;
use Illuminate\Console\Command;

class PurgeArchivedTenantCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tenant:purge-archived {tenant_id : ID of the archived tenant} {--confirm : Bypass interactive confirmation}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Permanently purge and anonymize PII for an archived tenant per UU PDP compliance (EXT-06)';

    /**
     * Execute the console command.
     */
    public function handle(TenantLifecyclePurgeService $purgeService): int
    {
        $tenantId = (int) $this->argument('tenant_id');

        if (! $this->option('confirm')) {
            $confirmed = $this->confirm("Apakah Anda yakin ingin melakukan purge permanen dan anonimisasi data PII untuk Tenant ID {$tenantId}? Tindakan ini tidak dapat dibatalkan.");
            if (! $confirmed) {
                $this->warn('Operasi purge tenant dibatalkan.');

                return self::FAILURE;
            }
        }

        try {
            $tenant = $purgeService->purgeTenant($tenantId);

            $this->info("Tenant '{$tenant->name}' (ID: {$tenantId}) berhasil di-purge dan data PII telah di-anonimkan.");

            return self::SUCCESS;
        } catch (DomainException $e) {
            $this->error('Gagal melakukan purge tenant: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
