<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Models\PaymentInbox;
use App\Services\Context\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Payment Inbox Dispatcher (Section 4 of Blueprint)
 *
 * Implements Asynchronous Inbox Pattern for Midtrans & external webhooks:
 * - Guarantees absolute deduplication via composite unique constraint (tenant_id, idempotency_key).
 * - Duplicate callbacks are safely acknowledged with 200 OK without re-triggering stock or ledger mutations.
 * - Out-of-band exceptions are captured without losing audit trails.
 */
class PaymentInboxDispatcher
{
    public function __construct(
        protected TenantContext $tenantContext
    ) {}

    /**
     * Dispatches an incoming webhook through the deduplicating inbox pattern.
     *
     * @param array<string, mixed> $payload
     * @param callable(array<string, mixed>): void $processor
     * @return array{status: string, message: string, duplicate: bool}
     */
    public function dispatch(
        int $tenantId,
        string $provider,
        string $idempotencyKey,
        string $eventType,
        array $payload,
        callable $processor
    ): array {
        // 1. Check if this exact webhook has already been recorded
        $existing = PaymentInbox::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        $inboxEntry = null;

        if ($existing) {
            if ($existing->status === 'processed') {
                Log::info("[PaymentInbox] Idempotent no-op: Webhook {$idempotencyKey} already processed.", [
                    'tenant_id' => $tenantId,
                    'provider' => $provider,
                ]);

                return [
                    'status' => 'already_processed',
                    'message' => 'Notification already processed successfully.',
                    'duplicate' => true,
                ];
            }

            if ($existing->status === 'processing') {
                Log::warning("[PaymentInbox] In-flight collision: Webhook {$idempotencyKey} is currently processing.", [
                    'tenant_id' => $tenantId,
                ]);

                return [
                    'status' => 'in_flight',
                    'message' => 'Notification currently in flight.',
                    'duplicate' => true,
                ];
            }

            // Retry for previously failed notification
            $existing->update([
                'status' => 'processing',
                'error_message' => null,
            ]);
            $inboxEntry = $existing;
        }

        // 2. Record to payment_inbox if not retrying an existing entry
        if (! $inboxEntry) {
            try {
                $inboxEntry = PaymentInbox::withoutGlobalScopes()->create([
                    'tenant_id' => $tenantId,
                    'provider' => $provider,
                    'idempotency_key' => $idempotencyKey,
                    'event_type' => $eventType,
                    'payload' => $payload,
                    'status' => 'processing',
                    'processed_at' => null,
                ]);
            } catch (QueryException $e) {
                // MySQL error 1062 / 23000: Duplicate entry under concurrent race
                if ($e->getCode() === '23000' || str_contains($e->getMessage(), '1062')) {
                    Log::info("[PaymentInbox] Race condition intercepted: Duplicate entry for {$idempotencyKey}.");

                    return [
                        'status' => 'already_processed',
                        'message' => 'Concurrent duplicate webhook received.',
                        'duplicate' => true,
                    ];
                }

                throw $e;
            }
        }

        // 3. Execute business processor atomically
        try {
            DB::transaction(function () use ($processor, $payload) {
                $processor($payload);
            });

            $inboxEntry->update([
                'status' => 'processed',
                'processed_at' => now(),
            ]);

            return [
                'status' => 'success',
                'message' => 'Webhook processed successfully.',
                'duplicate' => false,
            ];
        } catch (Throwable $e) {
            Log::error("[PaymentInbox] Processing failed for {$idempotencyKey}: {$e->getMessage()}", [
                'trace' => $e->getTraceAsString(),
            ]);

            $inboxEntry->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
