<?php

declare(strict_types=1);

namespace App\Modules\CRM\Services;

use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerAddress;
use App\Modules\Customers\Models\CustomerContact;
use App\Modules\Customers\Models\CustomerCreditAccount;
use App\Modules\Customers\Models\CustomerLoyaltyAccount;
use App\Modules\Customers\Models\CustomerMergeLog;
use App\Modules\Customers\Models\LoyaltyLedger;
use App\Modules\Customers\Models\Voucher;
use App\Modules\Pricing\Services\LoyaltyLedgerService;
use App\Modules\Sales\Models\Order;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * CustomerMergeService — Layanan penggabungan profil pelanggan ganda atomik.
 *
 * Mengikuti spesifikasi Section 5 Modul 13 CRM & T-CRM-02:
 * Memindahkan seluruh riwayat pesanan (orders), saldo poin loyalitas, saldo kredit,
 * dan kontak ke profil target, mencatat customer_merge_logs, serta me-soft delete
 * profil duplikat dengan merged_into_customer_id.
 */
class CustomerMergeService
{
    public function __construct(
        private readonly LoyaltyLedgerService $loyaltyLedgerService
    ) {}

    /**
     * Gabungkan profil pelanggan duplikat ke profil pelanggan tujuan secara atomik.
     *
     * @param  int  $tenantId
     * @param  int  $targetCustomerId  ID profil pelanggan utama yang dipertahankan
     * @param  int  $duplicateCustomerId  ID profil duplikat yang akan dilebur & dinonaktifkan
     * @param  int|null  $mergedByUserId
     * @param  string|null  $reason
     * @return CustomerMergeLog
     */
    public function merge(
        int $tenantId,
        int $targetCustomerId,
        int $duplicateCustomerId,
        ?int $mergedByUserId = null,
        ?string $reason = null
    ): CustomerMergeLog {
        if ($targetCustomerId === $duplicateCustomerId) {
            throw new InvalidArgumentException('Profil pelanggan tujuan dan duplikat tidak boleh sama.');
        }

        return DB::transaction(function () use (
            $tenantId,
            $targetCustomerId,
            $duplicateCustomerId,
            $mergedByUserId,
            $reason
        ) {
            // Kunci kedua profil pelanggan secara pesimis
            /** @var Customer|null $target */
            $target = Customer::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('id', $targetCustomerId)
                ->lockForUpdate()
                ->first();

            /** @var Customer|null $duplicate */
            $duplicate = Customer::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('id', $duplicateCustomerId)
                ->lockForUpdate()
                ->first();

            if (! $target || ! $duplicate) {
                throw new RuntimeException('Salah satu profil pelanggan yang akan digabungkan tidak ditemukan.');
            }

            if ($duplicate->trashed() || ! $duplicate->is_active) {
                throw new RuntimeException("Profil pelanggan duplikat #{$duplicateCustomerId} sudah nonaktif atau terhapus.");
            }

            $reassignedSummary = [
                'orders_count' => 0,
                'vouchers_count' => 0,
                'addresses_count' => 0,
                'contacts_count' => 0,
                'points_transferred' => 0,
            ];

            // 1. Pindahkan riwayat orders
            $ordersCount = Order::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('customer_id', $duplicateCustomerId)
                ->update(['customer_id' => $targetCustomerId]);
            $reassignedSummary['orders_count'] = $ordersCount;

            // 2. Pindahkan voucher
            $vouchersCount = Voucher::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('customer_id', $duplicateCustomerId)
                ->update(['customer_id' => $targetCustomerId]);
            $reassignedSummary['vouchers_count'] = $vouchersCount;

            // 3. Pindahkan buku alamat & kontak
            $addressesCount = CustomerAddress::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('customer_id', $duplicateCustomerId)
                ->update(['customer_id' => $targetCustomerId]);
            $reassignedSummary['addresses_count'] = $addressesCount;

            $contactsCount = CustomerContact::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('customer_id', $duplicateCustomerId)
                ->update(['customer_id' => $targetCustomerId]);
            $reassignedSummary['contacts_count'] = $contactsCount;

            // 4. Pindahkan saldo poin loyalitas
            $duplicatePoints = $this->loyaltyLedgerService->getCurrentBalance($duplicateCustomerId);
            if ($duplicatePoints > 0) {
                // Debet dari duplikat
                $this->loyaltyLedgerService->recordPoints(
                    tenantId: $tenantId,
                    customerId: $duplicateCustomerId,
                    points: -$duplicatePoints,
                    type: 'adjustment',
                    reference: "MERGE_OUT_TO_{$targetCustomerId}",
                    createdBy: $mergedByUserId
                );

                // Kreditkan ke target
                $this->loyaltyLedgerService->recordPoints(
                    tenantId: $tenantId,
                    customerId: $targetCustomerId,
                    points: $duplicatePoints,
                    type: 'bonus',
                    reference: "MERGE_IN_FROM_{$duplicateCustomerId}",
                    createdBy: $mergedByUserId
                );

                $reassignedSummary['points_transferred'] = $duplicatePoints;
            }

            // 5. Catat riwayat penggabungan ke customer_merge_logs
            /** @var CustomerMergeLog $mergeLog */
            $mergeLog = CustomerMergeLog::withoutGlobalScopes()->create([
                'tenant_id' => $tenantId,
                'source_customer_id' => $duplicateCustomerId,
                'target_customer_id' => $targetCustomerId,
                'merged_by_user_id' => $mergedByUserId,
                'reason' => $reason ?? "Penggabungan profil duplikat #{$duplicateCustomerId} ke #{$targetCustomerId}",
                'reassigned_records_json' => $reassignedSummary,
                'created_at' => Carbon::now(),
            ]);

            // 6. Tandai duplikat sebagai telah digabung dan soft-delete
            $duplicate->merged_into_customer_id = $target->id;
            $duplicate->is_active = false;
            $duplicate->save();
            $duplicate->delete(); // Soft delete

            return $mergeLog;
        });
    }
}
