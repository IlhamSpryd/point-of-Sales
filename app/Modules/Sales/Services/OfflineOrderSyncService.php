<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Models\Order;
use App\Models\Payment;
use App\Models\Shift;
use App\Models\User;
use App\Services\Context\TenantContext;
use App\Services\TransactionService;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OfflineOrderSyncService
{
    public function __construct(
        protected TransactionService $transactionService
    ) {}

    /**
     * Menyinkronkan kumpulan transaksi luring (offline orders batch) secara idempoten dan atomik (DEC-006 / TR-003).
     *
     * @param  array<int, array<string, mixed>>  $ordersData
     * @return array{synced_count: int, failed_count: int, results: array<int, array<string, mixed>>}
     */
    public function syncBatch(
        array $ordersData,
        int $userId,
        int $tenantId,
        int $storeId,
        ?int $deviceId = null
    ): array {
        $results = [];

        foreach ($ordersData as $index => $orderPayload) {
            $uuid = (string) ($orderPayload['offline_client_uuid'] ?? '');
            if (empty($uuid)) {
                $results[] = [
                    'index' => $index,
                    'offline_client_uuid' => null,
                    'status' => 'rejected',
                    'error' => 'offline_client_uuid is required for offline order synchronization.',
                ];
                continue;
            }

            // DEC-006: Pembatasan metode pembayaran offline HANYA boleh TUNAI (CASH)
            $paymentMethod = strtolower((string) ($orderPayload['payment_method'] ?? 'cash'));
            if ($paymentMethod !== 'cash') {
                $results[] = [
                    'index' => $index,
                    'offline_client_uuid' => $uuid,
                    'status' => 'rejected',
                    'error' => 'Offline transactions strictly restrict payment method to CASH only (DEC-006).',
                ];
                continue;
            }

            // Pemeriksaan Idempotensi: Jika UUID sudah tersinkronkan sebelumnya, kembalikan record tanpa membuat duplikasi
            $existing = Order::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('offline_client_uuid', $uuid)
                ->first();

            if ($existing) {
                $results[] = [
                    'index' => $index,
                    'offline_client_uuid' => $uuid,
                    'order_id' => $existing->id,
                    'order_code' => $existing->order_code,
                    'status' => 'already_synced',
                ];
                continue;
            }

            try {
                $syncedOrder = DB::transaction(function () use (
                    $orderPayload, $uuid, $userId, $tenantId, $storeId, $deviceId
                ) {
                    // Pastikan context tenant aktif
                    app(TenantContext::class)->setTenantId($tenantId);
                    app(TenantContext::class)->setStoreId($storeId);

                    // Selesaikan shift_id aktif kasir
                    $shiftId = $orderPayload['shift_id'] ?? null;
                    if (! $shiftId) {
                        $shiftId = Shift::withoutGlobalScopes()
                            ->where('tenant_id', $tenantId)
                            ->where('store_id', $storeId)
                            ->where(function ($q) use ($userId) {
                                $q->where('active_cashier_id', $userId)
                                    ->orWhere(function ($sub) use ($userId) {
                                        $sub->whereNull('active_cashier_id')->where('user_id', $userId);
                                    });
                            })
                            ->where('status', 'open')
                            ->value('id');
                    }

                    if (! $shiftId) {
                        // Fallback ke shift terbuka mana pun pada store tersebut
                        $shiftId = Shift::withoutGlobalScopes()
                            ->where('tenant_id', $tenantId)
                            ->where('store_id', $storeId)
                            ->where('status', 'open')
                            ->value('id');
                    }

                    $orderPayload['tenant_id'] = $tenantId;
                    $orderPayload['store_id'] = $storeId;
                    $orderPayload['user_id'] = $userId;
                    $orderPayload['shift_id'] = $shiftId;
                    $orderPayload['created_by_device_id'] = $deviceId ?? ($orderPayload['device_id'] ?? null);
                    $orderPayload['offline_client_uuid'] = $uuid;
                    $rawKey = (string) ($orderPayload['idempotency_key'] ?? '');
                    $orderPayload['idempotency_key'] = (! empty($rawKey) && strlen($rawKey) <= 36) ? $rawKey : (string) \Illuminate\Support\Str::uuid();
                    $orderPayload['payment_method'] = 'cash';
                    $orderPayload['is_offline_sync'] = true;
                    $orderPayload['offline_synced_at'] = now();

                    // Eksekusi transaksi melalui TransactionService
                    $order = $this->transactionService->createTransaction($orderPayload, $userId);

                    return $order;
                });

                $results[] = [
                    'index' => $index,
                    'offline_client_uuid' => $uuid,
                    'order_id' => $syncedOrder->id,
                    'order_code' => $syncedOrder->order_code,
                    'status' => 'synced',
                ];
            } catch (\Throwable $e) {
                Log::error("[OFFLINE SYNC] Gagal sinkronisasi order offline {$uuid}: ".$e->getMessage(), [
                    'trace' => $e->getTraceAsString(),
                ]);

                $results[] = [
                    'index' => $index,
                    'offline_client_uuid' => $uuid,
                    'status' => 'rejected',
                    'error' => $e->getMessage(),
                ];
            }
        }

        $syncedCount = count(array_filter($results, fn ($r) => in_array($r['status'], ['synced', 'already_synced'], true)));
        $failedCount = count(array_filter($results, fn ($r) => $r['status'] === 'rejected'));

        return [
            'synced_count' => $syncedCount,
            'failed_count' => $failedCount,
            'results' => $results,
        ];
    }
}
