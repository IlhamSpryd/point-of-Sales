<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Modules\Integration\Services\WhatsAppNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Job asinkron pemroses pengiriman struk digital WhatsApp (TR-006 / DEC-007).
 */
class SendWhatsAppReceiptJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;
    public int $timeout = 15;

    public function __construct(
        public int $outboxId
    ) {}

    public function handle(WhatsAppNotificationService $service): void
    {
        try {
            $service->processOutboxItem($this->outboxId);
        } catch (Throwable $e) {
            Log::error('SendWhatsAppReceiptJob unexpected failure: '.$e->getMessage(), [
                'outbox_id' => $this->outboxId,
            ]);
        }
    }
}

