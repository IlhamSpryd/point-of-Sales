<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Finance\Services\ApOutboxConsumerService;
use App\Modules\Finance\Services\ArOutboxConsumerService;
use Illuminate\Console\Command;

class ProcessSubledgerOutboxCommand extends Command
{
    protected $signature = 'finance:process-subledgers
                            {--limit=50 : Jumlah maksimum outbox yang diproses per eksekusi}
                            {--tenant= : Filter tenant ID tertentu}';

    protected $description = 'Memproses antrean outbox AP (Account Payable) dan AR (Account Receivable) ke Subledger dan General Ledger';

    public function handle(
        ApOutboxConsumerService $apConsumer,
        ArOutboxConsumerService $arConsumer
    ): int {
        $limit = (int) $this->option('limit');
        $tenantId = $this->option('tenant') !== null ? (int) $this->option('tenant') : null;

        $this->info("Memproses subledger outbox (limit: {$limit}, tenant: ".($tenantId ?? 'semua').')...');

        $apProcessed = $apConsumer->processPendingOutbox($tenantId, $limit);
        $arProcessed = $arConsumer->processPendingOutbox($tenantId, $limit);

        $this->info("Selesai memproses subledger outbox: {$apProcessed} AP diproses, {$arProcessed} AR diproses.");

        return self::SUCCESS;
    }
}
