<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application\Services;

use App\Enums\OrderStatus;
use App\Modules\Sales\Exceptions\ShiftClosedException;
use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Models\Shift;
use App\Modules\Sales\Models\Table;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Handles F&B table occupancy synchronization, shift resolution, and store terminals/accounts (ADR-108).
 */
class PosTableAndCustomerSyncService
{
    /**
     * Get transaction metrics for today.
     *
     * @return array{omzet: float, jumlah: int}
     */
    public function getTodayMetrics(): array
    {
        $today = Carbon::today();
        $metricsQuery = Order::whereDate('order_date', $today)
            ->where('order_status', OrderStatus::Paid)
            ->selectRaw('COALESCE(SUM(order_amount), 0) as omzet, COUNT(*) as jumlah')
            ->first();

        return [
            'omzet' => (float) ($metricsQuery->omzet ?? 0),
            'jumlah' => (int) ($metricsQuery->jumlah ?? 0),
        ];
    }

    /**
     * Zero-Trust shift guard.
     *
     * @throws ValidationException|ShiftClosedException if user has no open shift.
     */
    public function resolveOpenShiftOrFail(int $userId): int
    {
        $shiftId = Shift::where('user_id', $userId)
            ->where('status', 'open')
            ->value('id');

        if ($shiftId === null) {
            throw new ShiftClosedException('You have not opened a register shift. Please open a shift before processing transactions.');
        }

        return $shiftId;
    }

    /**
     * Synchronize table status to 'available' atomically when an order is finalized/voided/cancelled,
     * if no other active orders remain on that table.
     */
    public function syncTableStatusOnOrderFinalized(Order $order): void
    {
        if (! $order->table_id) {
            return;
        }

        $table = Table::withoutGlobalScopes()
            ->where('tenant_id', $order->tenant_id)
            ->where('store_id', $order->store_id)
            ->where('id', $order->table_id)
            ->lockForUpdate()
            ->first();

        if (! $table) {
            return;
        }

        $hasOtherActiveOrders = Order::withoutGlobalScopes()
            ->where('tenant_id', $table->tenant_id)
            ->where('store_id', $table->store_id)
            ->where('table_id', $table->id)
            ->where('id', '!=', $order->id)
            ->whereIn('order_status', [OrderStatus::Pending, OrderStatus::Paid])
            ->exists();

        if (! $hasOtherActiveOrders && $table->operational_status === 'occupied') {
            $table->update(['operational_status' => 'available']);
        }
    }

    /**
     * Retrieve active bank accounts for POS cashier payment selection.
     */
    public function getStoreBankAccounts(?int $tenantId, ?int $storeId): Collection
    {
        if (! $tenantId) {
            return collect();
        }

        $query = DB::table('cash_bank_accounts')
            ->where('tenant_id', $tenantId)
            ->where('is_active', 1)
            ->where('account_type', 'BANK_ACCOUNT');

        if ($storeId) {
            $query->where(fn ($q) => $q->where('store_id', $storeId)->orWhereNull('store_id'));
        }

        $accounts = $query->get();
        if ($accounts->isEmpty()) {
            $fallback = new \stdClass;
            $fallback->id = 0;
            $fallback->bank_name = 'Bank Central Asia (BCA)';
            $fallback->account_name = 'Rekening Operasional Toko';
            $fallback->bank_account_number = '8010 9928 1120';
            $fallback->bank_account_holder = 'Apeiron System Coffee';

            return collect([$fallback]);
        }

        return $accounts;
    }

    /**
     * Retrieve active EDC terminals for POS cashier card payments.
     */
    public function getStoreEdcTerminals(?int $tenantId, ?int $storeId): Collection
    {
        if (! $tenantId) {
            return collect();
        }

        $query = DB::table('devices')
            ->where('tenant_id', $tenantId)
            ->where('is_active', 1)
            ->where('device_type', 'pos_terminal');

        if ($storeId) {
            $query->where(fn ($q) => $q->where('store_id', $storeId)->orWhereNull('store_id'));
        }

        $terminals = $query->get();
        if ($terminals->isEmpty()) {
            $t1 = new \stdClass;
            $t1->id = 1;
            $t1->device_name = 'Terminal BCA #01';
            $t1->device_code = 'BCA-EDC-01';
            $t2 = new \stdClass;
            $t2->id = 2;
            $t2->device_name = 'Terminal Mandiri #02';
            $t2->device_code = 'MDR-EDC-02';

            return collect([$t1, $t2]);
        }

        return $terminals;
    }
}
