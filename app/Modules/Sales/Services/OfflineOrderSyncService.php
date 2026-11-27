<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Core\Context\TenantContext;
use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Models\Shift;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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
                    'sync_status' => 'failed',
                    'failed' => true,
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
                    'sync_status' => 'failed',
                    'failed' => true,
                    'error' => 'Offline transactions strictly restrict payment method to CASH only (DEC-006).',
                ];

                continue;
            }

            // Pemeriksaan Idempotensi: Jika UUID sudah tersinkronkan sebelumnya, kembalikan record tanpa membuat duplikasi
            $existing = Order::where('tenant_id', $tenantId)
                ->where('offline_client_uuid', $uuid)
                ->first();

            if ($existing) {
                $results[] = [
                    'index' => $index,
                    'offline_client_uuid' => $uuid,
                    'order_id' => $existing->id,
                    'order_code' => $existing->order_code,
                    'status' => 'already_synced',
                    'sync_status' => 'duplicate_skipped',
                    'duplicate_skipped' => true,
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

                    // Selesaikan attribution user dan shift kasir (Shared-Tablet Offline Attribution Guard PWA-01)
                    $creatorUserId = isset($orderPayload['offline_created_by_user_id']) ? (int) $orderPayload['offline_created_by_user_id'] : $userId;
                    $creatorShiftId = isset($orderPayload['offline_shift_id']) ? (int) $orderPayload['offline_shift_id'] : ($orderPayload['shift_id'] ?? null);

                    $targetUserId = $userId;
                    if ($creatorUserId > 0) {
                        $validCreator = \App\Modules\Organization\Models\User::where('tenant_id', $tenantId)
                            ->where('id', $creatorUserId)
                            ->exists();
                        if ($validCreator) {
                            $targetUserId = $creatorUserId;
                        }
                    }

                    $shiftId = null;
                    $isPostCloseReconciliation = false;

                    if ($creatorShiftId) {
                        $originShift = Shift::where('tenant_id', $tenantId)
                            ->where('store_id', $storeId)
                            ->where('id', $creatorShiftId)
                            ->first();

                        if ($originShift) {
                            $shiftId = $originShift->id;
                            if ($originShift->status !== 'open') {
                                $isPostCloseReconciliation = true;
                            }
                        }
                    }

                    if (! $shiftId) {
                        $shiftId = Shift::where('tenant_id', $tenantId)
                            ->where('store_id', $storeId)
                            ->where(function ($q) use ($targetUserId) {
                                $q->where('active_cashier_id', $targetUserId)
                                    ->orWhere(function ($sub) use ($targetUserId) {
                                        $sub->whereNull('active_cashier_id')->where('user_id', $targetUserId);
                                    });
                            })
                            ->where('status', 'open')
                            ->value('id');
                    }

                    if (! $shiftId) {
                        if ($targetUserId !== $userId) {
                            $isPostCloseReconciliation = true;
                        } else {
                            $shiftId = Shift::where('tenant_id', $tenantId)
                                ->where('store_id', $storeId)
                                ->where('status', 'open')
                                ->value('id');
                        }
                    }

                    $orderPayload['tenant_id'] = $tenantId;
                    $orderPayload['store_id'] = $storeId;
                    $orderPayload['user_id'] = $targetUserId;
                    $orderPayload['shift_id'] = $shiftId;
                    $orderPayload['created_by_device_id'] = $deviceId ?? ($orderPayload['device_id'] ?? null);
                    $orderPayload['offline_client_uuid'] = $uuid;
                    $rawKey = (string) ($orderPayload['idempotency_key'] ?? '');
                    $orderPayload['idempotency_key'] = (! empty($rawKey) && strlen($rawKey) <= 36) ? $rawKey : (string) Str::uuid();
                    $orderPayload['payment_method'] = 'cash';
                    $orderPayload['is_offline_sync'] = true;
                    $orderPayload['offline_post_close_reconciliation'] = $isPostCloseReconciliation;
                    $orderPayload['offline_synced_at'] = now();
                    if ($isPostCloseReconciliation) {
                        $orderPayload['source_reference'] = trim(($orderPayload['source_reference'] ?? '').' [OFFLINE POST-CLOSE RECONCILIATION]');
                    }

                    // Eksekusi transaksi melalui TransactionService
                    $order = $this->transactionService->createTransaction($orderPayload, $targetUserId);

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
                    'sync_status' => 'failed',
                    'failed' => true,
                    'error' => $e->getMessage(),
                ];
            }
        }

        $syncedCount = count(array_filter($results, fn ($r) => in_array($r['status'], ['synced', 'duplicate_skipped', 'already_synced'], true)));
        $failedCount = count(array_filter($results, fn ($r) => in_array($r['status'], ['failed', 'rejected'], true)));

        return [
            'synced_count' => $syncedCount,
            'failed_count' => $failedCount,
            'results' => $results,
        ];
    }
}
