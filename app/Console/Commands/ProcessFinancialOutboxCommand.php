<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Finance\Models\FinancialEventOutbox;
use App\Modules\Finance\Services\PostingEngineService;
use App\Services\Context\TenantContext;
use Illuminate\Console\Command;

class ProcessFinancialOutboxCommand extends Command
{
    protected $signature = 'finance:process-outbox
                            {--limit=100 : Jumlah maksimum outbox yang diproses per eksekusi}
                            {--tenant= : Filter tenant ID tertentu}';

    protected $description = 'Memproses rekaman peristiwa finansial dari antrean financial_event_outboxes ke General Ledger';

    public function handle(
        PostingEngineService $postingEngine,
        TenantContext $tenantContext
    ): int {
        $limit = (int) $this->option('limit');
        $tenantId = $this->option('tenant');

        $query = FinancialEventOutbox::withoutTenantScope()
            ->where(function ($q) {
                $q->where('status', 'PENDING')
                    ->orWhere(function ($sub) {
                        $sub->where('status', 'FAILED')
                            ->where('retry_count', '<', 5)
                            ->where('next_retry_at', '<=', now());
                    });
            })
            ->orderBy('id', 'asc')
            ->limit($limit);

        if ($tenantId) {
            $query->where('tenant_id', (int) $tenantId);
        }

        $records = $query->get();

        if ($records->isEmpty()) {
            $this->info('Tidak ada peristiwa finansial yang pending.');

            return self::SUCCESS;
        }

        $this->info("Memproses {$records->count()} peristiwa finansial...");
        $processed = 0;
        $failed = 0;

        foreach ($records as $outbox) {
            try {
                $tenantContext->runAs((int) $outbox->tenant_id, $outbox->store_id ? (int) $outbox->store_id : null, function () use ($postingEngine, $outbox) {
                    $postingEngine->processOutboxEvent($outbox);
                });
                $processed++;
                $this->line(" - Outbox #{$outbox->id} ({$outbox->event_type}): SUCCESS");
            } catch (\Throwable $e) {
                $failed++;
                $this->error(" - Outbox #{$outbox->id} ({$outbox->event_type}): FAILED - ".$e->getMessage());
            }
        }

        $this->info("Selesai. Berhasil: {$processed}, Gagal: {$failed}");

        return self::SUCCESS;
    }
}
