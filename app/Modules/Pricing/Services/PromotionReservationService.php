<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Services;

use App\Modules\Customers\Enums\VoucherStatusEnum;
use App\Modules\Customers\Models\Voucher;
use App\Modules\Pricing\Models\PromotionReservation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * PromotionReservationService — Manajemen reservasi kuota promosi anti-balapan (concurrency-safe).
 *
 * Mengikuti spesifikasi BR-PRC-003 & T-PRC-02:
 * Dua transaksi simultan memperebutkan kuota voucher terakhir hanya meloloskan 1 transaksi;
 * transaksi kedua ditolak karena kuota habis.
 */
class PromotionReservationService
{
    public const RESERVATION_LIFETIME_MINUTES = 15;

    /**
     * Reservasi kuota voucher secara atomik dengan masa berlaku 15 menit.
     *
     * @throws RuntimeException Jika kuota voucher habis atau voucher tidak aktif.
     */
    public function reserveVoucher(
        int $tenantId,
        int $storeId,
        int $voucherId,
        string $cartUuid,
        ?int $customerId = null,
        float $reservedAmount = 0.0
    ): PromotionReservation {
        return DB::transaction(function () use (
            $tenantId,
            $storeId,
            $voucherId,
            $cartUuid,
            $customerId,
            $reservedAmount
        ) {
            // Bersihkan reservasi lama yang sudah expired untuk voucher ini
            PromotionReservation::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('voucher_id', $voucherId)
                ->where('status', 'RESERVED')
                ->where('expires_at', '<', Carbon::now())
                ->update(['status' => 'RELEASED']);

            // Kunci baris voucher secara pesimis (lockForUpdate)
            /** @var Voucher|null $voucher */
            $voucher = Voucher::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('id', $voucherId)
                ->lockForUpdate()
                ->first();

            if (! $voucher) {
                throw new RuntimeException("Voucher #{$voucherId} tidak ditemukan.");
            }

            $rawStatus = $voucher->status;
            $currentStatus = $rawStatus instanceof VoucherStatusEnum
                ? $rawStatus->value
                : strtoupper((string) $rawStatus);

            if ($currentStatus !== VoucherStatusEnum::ACTIVE->value && $currentStatus !== 'ACTIVE') {
                throw new RuntimeException("Voucher '{$voucher->code}' tidak aktif.");
            }

            // Hitung total reservasi aktif yang masih menahan kuota
            $activeReservations = PromotionReservation::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('voucher_id', $voucherId)
                ->where('status', 'RESERVED')
                ->where('expires_at', '>=', Carbon::now())
                ->count();

            $maxUses = (int) $voucher->max_uses;
            $usedCount = (int) $voucher->used_count;
            $availableQuota = $maxUses - ($usedCount + $activeReservations);

            if ($availableQuota <= 0) {
                throw new RuntimeException("Kuota pemakaian voucher '{$voucher->code}' telah habis.");
            }

            // Batas per pelanggan jika ditentukan
            if ($customerId !== null && (int) $voucher->max_uses_per_customer > 0) {
                $customerUsed = PromotionReservation::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->where('voucher_id', $voucherId)
                    ->where('customer_id', $customerId)
                    ->whereIn('status', ['COMMITTED', 'RESERVED'])
                    ->where(function ($query) {
                        $query->where('status', 'COMMITTED')
                            ->orWhere('expires_at', '>=', Carbon::now());
                    })
                    ->count();

                if ($customerUsed >= (int) $voucher->max_uses_per_customer) {
                    throw new RuntimeException("Batas pemakaian voucher '{$voucher->code}' untuk pelanggan ini telah tercapai.");
                }
            }

            // Buat entri reservasi kuota baru
            /** @var PromotionReservation $reservation */
            $reservation = PromotionReservation::withoutGlobalScopes()->create([
                'tenant_id' => $tenantId,
                'store_id' => $storeId,
                'voucher_id' => $voucherId,
                'customer_id' => $customerId,
                'cart_uuid' => $cartUuid,
                'reserved_amount' => $reservedAmount,
                'status' => 'RESERVED',
                'expires_at' => Carbon::now()->addMinutes(self::RESERVATION_LIFETIME_MINUTES),
            ]);

            return $reservation;
        });
    }

    /**
     * Konfirmasi komit reservasi kuota setelah transaksi penjualan berhasil disahkan.
     *
     * @param int $reservationId
     * @param int<0, max> $orderId
     */
    public function commitReservation(int $reservationId, int $orderId): void
    {
        DB::transaction(function () use ($reservationId, $orderId) {
            /** @var PromotionReservation|null $reservation */
            $reservation = PromotionReservation::withoutGlobalScopes()
                ->where('id', $reservationId)
                ->lockForUpdate()
                ->first();

            if (! $reservation) {
                throw new RuntimeException("Reservasi #{$reservationId} tidak ditemukan.");
            }

            if ($reservation->status !== 'RESERVED') {
                return;
            }

            $reservation->status = 'COMMITTED';
            $reservation->order_id = max(0, $orderId);
            $reservation->save();

            // Naikkan hitungan used_count pada voucher
            if ($reservation->voucher_id) {
                Voucher::withoutGlobalScopes()
                    ->where('id', $reservation->voucher_id)
                    ->increment('used_count');
            }
        });
    }

    /**
     * Lepaskan kuota reservasi (misal keranjang dibatalkan atau pembayaran gagal).
     */
    public function releaseReservation(int $reservationId): void
    {
        PromotionReservation::withoutGlobalScopes()
            ->where('id', $reservationId)
            ->where('status', 'RESERVED')
            ->update(['status' => 'RELEASED']);
    }
}
