<?php

declare(strict_types=1);

namespace App\Modules\CRM\Services;

use App\Modules\Customers\Enums\CreditStatusEnum;
use App\Modules\Customers\Models\CustomerCreditAccount;
use App\Modules\Customers\Models\CustomerCreditLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * CustomerCreditLimitService — Manajemen batas plafon kredit dan akun piutang pelanggan B2B.
 *
 * Mengikuti spesifikasi BR-CRM-001 & BR-CRM-002:
 * Pelanggan hanya dapat bertransaksi tempo jika memiliki credit_limit > 0 dan status aktif.
 * Setiap mutasi batas kredit dicatat ke customer_credit_logs.
 */
class CustomerCreditLimitService
{
    /**
     * Tetapkan atau perbarui batas plafon kredit pelanggan.
     */
    public function setCreditLimit(
        int $tenantId,
        int $customerId,
        float|string $creditLimit,
        int $paymentTermsDays = 30,
        int $gracePeriodDays = 7,
        ?int $actorUserId = null,
        ?string $reason = null
    ): CustomerCreditAccount {
        return DB::transaction(function () use (
            $tenantId,
            $customerId,
            $creditLimit,
            $paymentTermsDays,
            $gracePeriodDays,
            $actorUserId,
            $reason
        ) {
            $limitStr = bcadd((string) (float) $creditLimit, '0', 2);
            if (bccomp($limitStr, '0.00', 2) < 0) {
                throw new InvalidArgumentException('Batas kredit tidak boleh bernilai negatif.');
            }

            /** @var CustomerCreditAccount|null $account */
            $account = CustomerCreditAccount::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('customer_id', $customerId)
                ->lockForUpdate()
                ->first();

            $oldLimit = $account ? (string) $account->credit_limit : '0.00';
            $oldStatus = $account ? $account->credit_status->value : CreditStatusEnum::ACTIVE->value;

            if (! $account) {
                $account = CustomerCreditAccount::withoutGlobalScopes()->create([
                    'tenant_id' => $tenantId,
                    'customer_id' => $customerId,
                    'credit_limit' => $limitStr,
                    'payment_terms_days' => $paymentTermsDays,
                    'grace_period_days' => $gracePeriodDays,
                    'credit_status' => CreditStatusEnum::ACTIVE,
                    'risk_rating' => 'LOW',
                    'reserved_exposure' => '0.00',
                    'invoiced_exposure' => '0.00',
                    'unapplied_credit_balance' => '0.00',
                    'last_reconciled_at' => Carbon::now(),
                    'updated_by_user_id' => $actorUserId,
                ]);
            } else {
                $account->credit_limit = $limitStr;
                $account->payment_terms_days = $paymentTermsDays;
                $account->grace_period_days = $gracePeriodDays;
                $account->credit_status = CreditStatusEnum::ACTIVE;
                $account->updated_by_user_id = $actorUserId;
                $account->save();
            }

            // Catat log perubahan kredit secara append-only
            CustomerCreditLog::withoutGlobalScopes()->create([
                'tenant_id' => $tenantId,
                'customer_id' => $customerId,
                'event_type' => 'LIMIT_ADJUSTMENT',
                'old_values_json' => ['credit_limit' => $oldLimit, 'status' => $oldStatus],
                'new_values_json' => ['credit_limit' => $limitStr, 'status' => CreditStatusEnum::ACTIVE->value],
                'reference_type' => 'MANUAL_OVERRIDE',
                'reference_id' => null,
                'actor_user_id' => $actorUserId,
                'reason' => $reason ?? 'Pembaruan batas plafon kredit pelanggan',
                'created_at' => Carbon::now(),
            ]);

            return $account;
        });
    }

    /**
     * Periksa ketersediaan sisa plafon kredit untuk transaksi tempo sejumlah nilai tertentu.
     */
    public function checkCreditAvailability(
        int $tenantId,
        int $customerId,
        float|string $requiredAmount
    ): bool {
        /** @var numeric-string $amountStr */
        $amountStr = bcadd((string) (float) $requiredAmount, '0', 2);

        /** @var CustomerCreditAccount|null $account */
        $account = CustomerCreditAccount::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('customer_id', $customerId)
            ->first();

        if (! $account) {
            return false;
        }

        if ($account->credit_status->isBlocked()) {
            return false;
        }

        $availableCredit = $account->getAvailableCredit();

        return bccomp($availableCredit, $amountStr, 2) >= 0;
    }

    /**
     * Reservasikan eksposur kredit pelanggan untuk Sales Order atau transaksi kasir tempo.
     */
    public function reserveCredit(
        int $tenantId,
        int $customerId,
        float|string $amount,
        string $referenceType,
        int $referenceId,
        ?int $actorUserId = null
    ): void {
        DB::transaction(function () use (
            $tenantId,
            $customerId,
            $amount,
            $referenceType,
            $referenceId,
            $actorUserId
        ) {
            /** @var numeric-string $amountStr */
            $amountStr = bcadd((string) (float) $amount, '0', 2);

            /** @var CustomerCreditAccount|null $account */
            $account = CustomerCreditAccount::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('customer_id', $customerId)
                ->lockForUpdate()
                ->first();

            if (! $account) {
                throw new RuntimeException("Akun kredit pelanggan #{$customerId} tidak ditemukan.");
            }

            if ($account->credit_status->isBlocked()) {
                throw new RuntimeException("Status kredit pelanggan sedang diblokir ({$account->credit_status->value}).");
            }

            $availableCredit = $account->getAvailableCredit();
            if (bccomp($availableCredit, $amountStr, 2) < 0) {
                throw new RuntimeException(
                    "Sisa batas kredit (Rp {$availableCredit}) tidak mencukupi untuk transaksi sejumlah Rp {$amountStr}."
                );
            }

            /** @var numeric-string $oldReserved */
            $oldReserved = bcadd((string) (float) $account->reserved_exposure, '0', 2);
            $newReserved = bcadd($oldReserved, $amountStr, 2);

            $account->reserved_exposure = $newReserved;
            $account->updated_by_user_id = $actorUserId;
            $account->save();

            CustomerCreditLog::withoutGlobalScopes()->create([
                'tenant_id' => $tenantId,
                'customer_id' => $customerId,
                'event_type' => 'EXPOSURE_RESERVATION',
                'old_values_json' => ['reserved_exposure' => $oldReserved],
                'new_values_json' => ['reserved_exposure' => $newReserved],
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'actor_user_id' => $actorUserId,
                'reason' => "Reservasi kredit untuk {$referenceType} #{$referenceId}",
                'created_at' => Carbon::now(),
            ]);
        });
    }
}
