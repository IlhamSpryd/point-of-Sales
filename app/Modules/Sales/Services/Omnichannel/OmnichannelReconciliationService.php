<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services\Omnichannel;

use App\Models\ChannelOrderLog;
use App\Models\Order;
use App\Services\Context\TenantContext;
use Carbon\Carbon;

class OmnichannelReconciliationService
{
    public function __construct(
        protected TenantContext $tenantContext
    ) {}

    /**
     * Rekonsiliasi perbedaan status antara channel_order_logs dan orders.
     *
     * @return array{checked: int, synced_completed: int, marked_failed: int, drift_detected: int}
     */
    public function reconcileChannelOrders(?int $tenantId = null, int $stuckThresholdMinutes = 15): array
    {
        $tenantId = $tenantId ?? (int) $this->tenantContext->getTenantId();

        $query = ChannelOrderLog::withoutGlobalScopes();
        if ($tenantId > 0) {
            $query->where('tenant_id', $tenantId);
        }

        $logs = $query->whereIn('status', ['pending', 'processing'])
            ->where('created_at', '<=', Carbon::now()->subMinutes($stuckThresholdMinutes))
            ->get();

        $syncedCompleted = 0;
        $markedFailed = 0;
        $driftDetected = 0;

        foreach ($logs as $log) {
            $deterministicKey = substr(hash('sha256', "channel:{$log->provider}:{$log->external_order_id}"), 0, 36);

            $order = Order::withoutGlobalScopes()
                ->where('tenant_id', $log->tenant_id)
                ->where(function ($q) use ($log, $deterministicKey) {
                    if ($log->order_id) {
                        $q->where('id', $log->order_id);
                    }
                    $q->orWhere('idempotency_key', $deterministicKey);
                })
                ->first();

            if ($order) {
                // Order ternyata sudah terbuat di tabel orders tapi status log tertinggal
                $log->update([
                    'status' => 'completed',
                    'order_id' => $order->id,
                    'error_message' => null,
                ]);
                $syncedCompleted++;
                $driftDetected++;
            } else {
                // Tidak ada order yang terbentuk dan log menggantung melebihi ambang batas waktu
                $log->update([
                    'status' => 'failed',
                    'error_message' => "Batas waktu pemrosesan webhook habis (> {$stuckThresholdMinutes} menit). Ditandai gagal oleh rekonsiliasi otomatis.",
                ]);
                $markedFailed++;
                $driftDetected++;
            }
        }

        return [
            'checked' => $logs->count(),
            'synced_completed' => $syncedCompleted,
            'marked_failed' => $markedFailed,
            'drift_detected' => $driftDetected,
        ];
    }
}
