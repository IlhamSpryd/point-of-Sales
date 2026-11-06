<?php

declare(strict_types=1);

namespace App\Modules\Integration\Services;

use App\Jobs\SendWhatsAppReceiptJob;
use App\Modules\Integration\Contracts\WhatsAppGatewayInterface;
use App\Modules\Integration\Models\WhatsAppMessageOutbox;
use App\Modules\Sales\Models\Order;
use App\Services\Context\TenantContext;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Service Notifikasi WhatsApp Business API Terisolasi (TR-006 / DEC-007).
 * Menerapkan Transactional Outbox Pattern untuk menjamin isolasi penuh dari kegagalan eksternal.
 */
class WhatsAppNotificationService
{
    public function __construct(
        protected WhatsAppGatewayInterface $gateway,
        protected TenantContext $tenantContext
    ) {}

    /**
     * Normalisasi nomor telepon seluler Indonesia ke format internasional E.164.
     */
    public function normalizePhoneNumber(string $phone): string
    {
        $clean = preg_replace('/[^0-9+]/', '', trim($phone)) ?? '';

        if (str_starts_with($clean, '+')) {
            return $clean;
        }

        if (str_starts_with($clean, '62')) {
            return '+'.$clean;
        }

        if (str_starts_with($clean, '08')) {
            return '+62'.substr($clean, 1);
        }

        if (str_starts_with($clean, '8')) {
            return '+62'.$clean;
        }

        return '+'.$clean;
    }

    /**
     * Antrekan pengiriman struk digital WhatsApp ke outbox.
     * Tidak pernah melempar Exception ke pemanggil agar transaksi penjualan tidak pernah gagal (DEC-007).
     */
    public function queueReceipt(Order $order, string $recipientPhone, ?string $receiptUrl = null): ?WhatsAppMessageOutbox
    {
        try {
            $normalizedPhone = $this->normalizePhoneNumber($recipientPhone);

            $payload = [
                'order_id' => $order->id,
                'order_code' => $order->order_code,
                'customer_name' => $order->customer?->name ?? 'Pelanggan Setia',
                'store_name' => $order->store?->name ?? 'Apeiron POS',
                'total_amount' => (string) ($order->order_amount ?? $order->total_amount ?? '0.00'),
                'items_count' => $order->orderItems()->count(),
                'receipt_url' => $receiptUrl ?? url('/pos/receipt/'.$order->order_code),
            ];

            $outbox = WhatsAppMessageOutbox::create([
                'tenant_id' => $order->tenant_id,
                'recipient_phone' => $normalizedPhone,
                'message_type' => 'RECEIPT',
                'template_id' => 'pos_digital_receipt_v1',
                'payload' => $payload,
                'status' => WhatsAppMessageOutbox::STATUS_PENDING,
                'attempts' => 0,
            ]);

            // Dispatch asinkron ke worker queue
            SendWhatsAppReceiptJob::dispatch($outbox->id);

            return $outbox;
        } catch (Throwable $e) {
            Log::error('Gagal memasukkan notifikasi WhatsApp ke outbox (fail-safe mode aktif): '.$e->getMessage(), [
                'order_id' => $order->id ?? null,
                'phone' => $recipientPhone,
            ]);

            return null;
        }
    }

    /**
     * Memproses 1 item antrean outbox dengan klaim atomik.
     */
    public function processOutboxItem(int $outboxId): bool
    {
        // Klaim atomik mencegah double-send antar concurrent workers
        $claimed = WhatsAppMessageOutbox::withoutTenantScope()
            ->where('id', $outboxId)
            ->whereIn('status', [WhatsAppMessageOutbox::STATUS_PENDING, WhatsAppMessageOutbox::STATUS_FAILED])
            ->where('attempts', '<', 3)
            ->update([
                'status' => WhatsAppMessageOutbox::STATUS_PROCESSING,
                'lease_expires_at' => now()->addMinutes(5),
            ]);

        if ($claimed === 0) {
            return false;
        }

        /** @var WhatsAppMessageOutbox|null $outbox */
        $outbox = WhatsAppMessageOutbox::withoutTenantScope()->find($outboxId);
        if ($outbox === null) {
            return false;
        }

        try {
            // Set konteks tenant untuk eksekusi terisolasi
            $prevTenantId = $this->tenantContext->getTenantId();
            $this->tenantContext->setTenantId($outbox->tenant_id);

            $payload = $outbox->payload;
            $parameters = [
                'customer_name' => $payload['customer_name'] ?? 'Pelanggan',
                'store_name' => $payload['store_name'] ?? 'Toko',
                'order_code' => $payload['order_code'] ?? '-',
                'total_amount' => $payload['total_amount'] ?? '0',
                'receipt_url' => $payload['receipt_url'] ?? '',
            ];

            $result = $this->gateway->sendTemplateMessage(
                $outbox->recipient_phone,
                $outbox->template_id ?? 'pos_digital_receipt_v1',
                $parameters
            );

            if ($result['success']) {
                $outbox->update([
                    'status' => WhatsAppMessageOutbox::STATUS_SENT,
                    'sent_at' => now(),
                    'provider_message_id' => $result['message_id'],
                    'last_error' => null,
                ]);

                return true;
            }

            // Gagal kirim dari sisi provider
            $newAttempts = $outbox->attempts + 1;
            $outbox->update([
                'attempts' => $newAttempts,
                'status' => $newAttempts >= 3 ? WhatsAppMessageOutbox::STATUS_FAILED : WhatsAppMessageOutbox::STATUS_PENDING,
                'last_error' => $result['error'] ?? 'Pengiriman WhatsApp ditolak gateway provider',
            ]);

            return false;
        } catch (Throwable $e) {
            $newAttempts = $outbox->attempts + 1;
            $outbox->update([
                'attempts' => $newAttempts,
                'status' => $newAttempts >= 3 ? WhatsAppMessageOutbox::STATUS_FAILED : WhatsAppMessageOutbox::STATUS_PENDING,
                'last_error' => $e->getMessage(),
            ]);

            return false;
        } finally {
            if (isset($prevTenantId)) {
                $this->tenantContext->setTenantId($prevTenantId);
            }
        }
    }
}
