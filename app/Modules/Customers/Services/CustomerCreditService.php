<?php

declare(strict_types=1);

namespace App\Modules\Customers\Services;

use App\Models\Customer;
use App\Models\User;
use App\Modules\Customers\DataTransferObjects\CreditEvaluationResultDto;
use App\Modules\Customers\Enums\CreditEvaluationStatusEnum;
use App\Modules\Customers\Enums\CreditStatusEnum;
use App\Modules\Customers\Models\CustomerCreditAccount;
use App\Modules\Customers\Models\CustomerCreditLog;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Settings\Services\AuditTrailService;
use App\Services\Context\TenantContext;
use Carbon\Carbon;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class CustomerCreditService
{
    public function __construct(
        protected AuditTrailService $auditTrailService,
        protected TenantContext $tenantContext
    ) {}

    /**
     * Mengambil atau membuat CustomerCreditAccount secara aman.
     */
    public function getOrCreateCreditAccount(int|Customer $customer, ?int $tenantId = null): CustomerCreditAccount
    {
        $customerId = $customer instanceof Customer ? (int) $customer->id : (int) $customer;
        $tId = $tenantId ?: (int) ($customer instanceof Customer ? $customer->tenant_id : $this->tenantContext->requireTenantId());

        return CustomerCreditAccount::withoutGlobalScopes()->firstOrCreate(
            ['tenant_id' => $tId, 'customer_id' => $customerId],
            [
                'credit_limit' => '0.00',
                'payment_terms_days' => 0,
                'grace_period_days' => 0,
                'credit_status' => CreditStatusEnum::ACTIVE,
                'risk_rating' => 'NORMAL',
                'reserved_exposure' => '0.00',
                'invoiced_exposure' => '0.00',
                'unapplied_credit_balance' => '0.00',
            ]
        );
    }

    /**
     * Mengevaluasi eksposur kredit dan kelayakan komersial pelanggan.
     */
    public function evaluateCreditExposure(int $tenantId, int $customerId, string $additionalAmount = '0.00'): CreditEvaluationResultDto
    {
        $account = $this->getOrCreateCreditAccount($customerId, $tenantId);

        $limit = (string) $account->credit_limit;
        $reserved = (string) $account->reserved_exposure;
        $invoiced = (string) $account->invoiced_exposure;
        $unapplied = (string) $account->unapplied_credit_balance;
        $currentExposure = $account->getTotalExposure();
        $available = $account->getAvailableCredit();

        $addAmount = bcadd((string) $additionalAmount, '0', 2);
        $projectedTotal = bcadd($currentExposure, $addAmount, 2);

        // 1. Cek status kredit (ON_HOLD / SUSPENDED)
        if ($account->credit_status->isBlocked()) {
            return new CreditEvaluationResultDto(
                status: CreditEvaluationStatusEnum::HARD_BLOCKED,
                allowed: false,
                creditLimit: $limit,
                reservedExposure: $reserved,
                invoicedExposure: $invoiced,
                unappliedCredit: $unapplied,
                currentTotalExposure: $currentExposure,
                additionalAmount: $addAmount,
                projectedTotalExposure: $projectedTotal,
                availableCredit: $available,
                isOverdue: false,
                reason: "Akun kredit pelanggan berstatus {$account->credit_status->value} ({$account->credit_status->label()}). Transaksi kredit dibekukan."
            );
        }

        // 2. Cek apakah ada invoice overdue melewati grace period
        $hasOverdue = false;
        if (DB::getSchemaBuilder()->hasTable('customer_invoices')) {
            $cutoffDate = Carbon::today()->subDays((int) $account->grace_period_days)->toDateString();
            $hasOverdue = DB::table('customer_invoices')
                ->where('tenant_id', $tenantId)
                ->where('customer_id', $customerId)
                ->whereIn('status', ['POSTED', 'PARTIALLY_PAID'])
                ->where('balance_due', '>', 0)
                ->where('due_date', '<', $cutoffDate)
                ->exists();
        }

        if ($hasOverdue) {
            return new CreditEvaluationResultDto(
                status: CreditEvaluationStatusEnum::HARD_BLOCKED,
                allowed: false,
                creditLimit: $limit,
                reservedExposure: $reserved,
                invoicedExposure: $invoiced,
                unappliedCredit: $unapplied,
                currentTotalExposure: $currentExposure,
                additionalAmount: $addAmount,
                projectedTotalExposure: $projectedTotal,
                availableCredit: $available,
                isOverdue: true,
                reason: 'Pelanggan memiliki tagihan piutang (invoice) yang telah jatuh tempo melewati batas toleransi (grace period).'
            );
        }

        // 3. Validasi batas plafon kredit (Credit Limit)
        if (bccomp($addAmount, '0.00', 2) > 0) {
            if (bccomp($limit, '0.00', 2) <= 0) {
                return new CreditEvaluationResultDto(
                    status: CreditEvaluationStatusEnum::HARD_BLOCKED,
                    allowed: false,
                    creditLimit: $limit,
                    reservedExposure: $reserved,
                    invoicedExposure: $invoiced,
                    unappliedCredit: $unapplied,
                    currentTotalExposure: $currentExposure,
                    additionalAmount: $addAmount,
                    projectedTotalExposure: $projectedTotal,
                    availableCredit: $available,
                    isOverdue: false,
                    reason: 'Pelanggan tidak memiliki plafon kredit (Credit Limit = Rp 0,00).'
                );
            }

            if (bccomp($projectedTotal, $limit, 2) > 0) {
                $exceeded = bcsub($projectedTotal, $limit, 2);

                return new CreditEvaluationResultDto(
                    status: CreditEvaluationStatusEnum::HARD_BLOCKED,
                    allowed: false,
                    creditLimit: $limit,
                    reservedExposure: $reserved,
                    invoicedExposure: $invoiced,
                    unappliedCredit: $unapplied,
                    currentTotalExposure: $currentExposure,
                    additionalAmount: $addAmount,
                    projectedTotalExposure: $projectedTotal,
                    availableCredit: $available,
                    isOverdue: false,
                    reason: 'Total eksposur yang diproyeksikan (Rp '.number_format((float) $projectedTotal, 2, ',', '.').') melebihi batas kredit (Rp '.number_format((float) $limit, 2, ',', '.').') sebesar Rp '.number_format((float) $exceeded, 2, ',', '.').'.'
                );
            }

            // Warning jika eksposur mencapai >= 80% dari plafon kredit
            $eightyPercent = bcdiv(bcmul($limit, '80', 4), '100', 2);
            if (bccomp($projectedTotal, $eightyPercent, 2) >= 0) {
                return new CreditEvaluationResultDto(
                    status: CreditEvaluationStatusEnum::SOFT_WARNING,
                    allowed: true,
                    creditLimit: $limit,
                    reservedExposure: $reserved,
                    invoicedExposure: $invoiced,
                    unappliedCredit: $unapplied,
                    currentTotalExposure: $currentExposure,
                    additionalAmount: $addAmount,
                    projectedTotalExposure: $projectedTotal,
                    availableCredit: $available,
                    isOverdue: false,
                    reason: 'Peringatan: Total eksposur kredit pelanggan telah mencapai lebih dari 80% dari batas plafon yang ditentukan.'
                );
            }
        }

        return new CreditEvaluationResultDto(
            status: CreditEvaluationStatusEnum::ALLOWED,
            allowed: true,
            creditLimit: $limit,
            reservedExposure: $reserved,
            invoicedExposure: $invoiced,
            unappliedCredit: $unapplied,
            currentTotalExposure: $currentExposure,
            additionalAmount: $addAmount,
            projectedTotalExposure: $projectedTotal,
            availableCredit: $available,
            isOverdue: false,
            reason: null
        );
    }

    /**
     * Mereservasi eksposur kredit saat konfirmasi Sales Order (CONFIRMED).
     * Mengunci record CustomerCreditAccount (Deterministic Lock Step 2 A0/A8).
     */
    public function reserveOrderExposure(SalesOrder $salesOrder, User $actor, bool $allowOverride = false, ?string $overrideReason = null): void
    {
        $tenantId = (int) $salesOrder->tenant_id;
        $customerId = (int) $salesOrder->customer_id;
        $orderTotal = (string) $salesOrder->total_amount;

        // Pastikan akun kredit ada
        $this->getOrCreateCreditAccount($customerId, $tenantId);

        // Kunci baris CustomerCreditAccount secara eksklusif (Anti-Deadlock Step 2 A0/A8)
        /** @var CustomerCreditAccount $lockedAccount */
        $lockedAccount = CustomerCreditAccount::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('customer_id', $customerId)
            ->lockForUpdate()
            ->firstOrFail();

        $eval = $this->evaluateCreditExposure($tenantId, $customerId, $orderTotal);

        if ($eval->status === CreditEvaluationStatusEnum::HARD_BLOCKED) {
            if ($allowOverride) {
                // Verifikasi otorisasi override kredit
                if (! Gate::forUser($actor)->allows('overrideCredit', [Customer::class, $salesOrder->customer])) {
                    throw new DomainException("Pengguna '{$actor->name}' tidak memiliki wewenang (izin 'credit.override') untuk mengesahkan override kredit pesanan ini.");
                }

                // Catat log override kredit
                CustomerCreditLog::create([
                    'tenant_id' => $tenantId,
                    'customer_id' => $customerId,
                    'event_type' => 'CREDIT_OVERRIDE',
                    'old_values_json' => [
                        'reserved_exposure' => (string) $lockedAccount->reserved_exposure,
                        'total_exposure' => $lockedAccount->getTotalExposure(),
                    ],
                    'new_values_json' => [
                        'approved_override_amount' => $orderTotal,
                        'reason' => $eval->reason,
                    ],
                    'reference_type' => SalesOrder::class,
                    'reference_id' => $salesOrder->id,
                    'actor_user_id' => $actor->id,
                    'reason' => $overrideReason ?: "Disetujui override kredit manual oleh {$actor->name}",
                ]);

                $salesOrder->credit_override_approved_by = $actor->id;
                $salesOrder->credit_override_reason = $overrideReason ?: "Disetujui override kredit manual oleh {$actor->name}";
            } else {
                throw new DomainException($eval->reason);
            }
        }

        // Tambahkan reserved_exposure
        $oldReserved = (string) $lockedAccount->reserved_exposure;
        $newReserved = bcadd($oldReserved, $orderTotal, 2);
        $lockedAccount->reserved_exposure = $newReserved;
        $lockedAccount->save();

        $salesOrder->reserved_credit_amount = $orderTotal;
        $salesOrder->save();

        CustomerCreditLog::create([
            'tenant_id' => $tenantId,
            'customer_id' => $customerId,
            'event_type' => 'EXPOSURE_RESERVED',
            'old_values_json' => ['reserved_exposure' => $oldReserved],
            'new_values_json' => ['reserved_exposure' => $newReserved],
            'reference_type' => SalesOrder::class,
            'reference_id' => $salesOrder->id,
            'actor_user_id' => $actor->id,
            'reason' => "Reservasi eksposur kredit untuk Sales Order #{$salesOrder->so_number}",
        ]);

        $this->auditTrailService->log(
            tenantId: $tenantId,
            action: 'credit.reserve_exposure',
            userId: $actor->id,
            storeId: (int) $salesOrder->store_id,
            subjectType: CustomerCreditAccount::class,
            subjectId: $lockedAccount->id,
            description: 'Reservasi eksposur kredit sebesar Rp '.number_format((float) $orderTotal, 2, ',', '.')." untuk SO #{$salesOrder->so_number}",
            newValues: ['reserved_exposure' => $newReserved]
        );
    }

    /**
     * Melepaskan reservasi eksposur kredit saat Sales Order dibatalkan atau ditutup.
     */
    public function releaseOrderExposure(SalesOrder $salesOrder, User $actor, ?string $reason = null): void
    {
        $tenantId = (int) $salesOrder->tenant_id;
        $customerId = (int) $salesOrder->customer_id;
        $releaseAmount = (string) $salesOrder->reserved_credit_amount;

        if (bccomp($releaseAmount, '0.00', 2) <= 0) {
            return;
        }

        /** @var CustomerCreditAccount $lockedAccount */
        $lockedAccount = CustomerCreditAccount::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('customer_id', $customerId)
            ->lockForUpdate()
            ->first();

        if ($lockedAccount === null) {
            return;
        }

        $oldReserved = (string) $lockedAccount->reserved_exposure;
        $newReserved = bcsub($oldReserved, $releaseAmount, 2);
        if (bccomp($newReserved, '0.00', 2) < 0) {
            $newReserved = '0.00';
        }

        $lockedAccount->reserved_exposure = $newReserved;
        $lockedAccount->save();

        $salesOrder->reserved_credit_amount = '0.00';
        $salesOrder->save();

        CustomerCreditLog::create([
            'tenant_id' => $tenantId,
            'customer_id' => $customerId,
            'event_type' => 'EXPOSURE_RELEASED',
            'old_values_json' => ['reserved_exposure' => $oldReserved],
            'new_values_json' => ['reserved_exposure' => $newReserved],
            'reference_type' => SalesOrder::class,
            'reference_id' => $salesOrder->id,
            'actor_user_id' => $actor->id,
            'reason' => $reason ?: "Pelepasan reservasi kredit SO #{$salesOrder->so_number}",
        ]);

        $this->auditTrailService->log(
            tenantId: $tenantId,
            action: 'credit.release_exposure',
            userId: $actor->id,
            storeId: (int) $salesOrder->store_id,
            subjectType: CustomerCreditAccount::class,
            subjectId: $lockedAccount->id,
            description: 'Pelepasan eksposur kredit sebesar Rp '.number_format((float) $releaseAmount, 2, ',', '.')." untuk SO #{$salesOrder->so_number}",
            newValues: ['reserved_exposure' => $newReserved]
        );
    }

    /**
     * Mengubah batas kredit (Credit Limit) pelanggan.
     */
    public function updateCreditLimit(int $customerId, string $newLimit, User $actor, string $reason): CustomerCreditAccount
    {
        $tenantId = (int) ($actor->tenant_id ?: $this->tenantContext->requireTenantId());

        return DB::transaction(function () use ($tenantId, $customerId, $newLimit, $actor, $reason) {
            $customer = Customer::withoutGlobalScopes()->where('tenant_id', $tenantId)->findOrFail($customerId);
            Gate::forUser($actor)->authorize('manageCreditLimit', $customer);

            $account = $this->getOrCreateCreditAccount($customer, $tenantId);

            /** @var CustomerCreditAccount $locked */
            $locked = CustomerCreditAccount::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('id', $account->id)
                ->lockForUpdate()
                ->firstOrFail();

            $oldLimit = (string) $locked->credit_limit;
            $formattedNewLimit = bcadd((string) $newLimit, '0', 2);

            $locked->credit_limit = $formattedNewLimit;
            $locked->updated_by_user_id = $actor->id;
            $locked->save();

            CustomerCreditLog::create([
                'tenant_id' => $tenantId,
                'customer_id' => $customerId,
                'event_type' => 'LIMIT_CHANGED',
                'old_values_json' => ['credit_limit' => $oldLimit],
                'new_values_json' => ['credit_limit' => $formattedNewLimit],
                'actor_user_id' => $actor->id,
                'reason' => $reason,
            ]);

            $this->auditTrailService->log(
                tenantId: $tenantId,
                action: 'credit.update_limit',
                userId: $actor->id,
                storeId: null,
                subjectType: CustomerCreditAccount::class,
                subjectId: $locked->id,
                description: "Batas kredit {$customer->name} diperbarui dari Rp {$oldLimit} menjadi Rp {$formattedNewLimit}. Alasan: {$reason}",
                newValues: ['credit_limit' => $formattedNewLimit]
            );

            return $locked;
        });
    }

    /**
     * Mengubah termin pembayaran dan toleransi jatuh tempo (Grace Period).
     */
    public function updateCreditTerms(int $customerId, int $paymentTermsDays, int $gracePeriodDays, User $actor, string $reason): CustomerCreditAccount
    {
        $tenantId = (int) ($actor->tenant_id ?: $this->tenantContext->requireTenantId());

        return DB::transaction(function () use ($tenantId, $customerId, $paymentTermsDays, $gracePeriodDays, $actor, $reason) {
            $customer = Customer::withoutGlobalScopes()->where('tenant_id', $tenantId)->findOrFail($customerId);
            Gate::forUser($actor)->authorize('manageCreditLimit', $customer);

            $account = $this->getOrCreateCreditAccount($customer, $tenantId);

            /** @var CustomerCreditAccount $locked */
            $locked = CustomerCreditAccount::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('id', $account->id)
                ->lockForUpdate()
                ->firstOrFail();

            $oldTerms = [
                'payment_terms_days' => $locked->payment_terms_days,
                'grace_period_days' => $locked->grace_period_days,
            ];

            $locked->payment_terms_days = max(0, $paymentTermsDays);
            $locked->grace_period_days = max(0, $gracePeriodDays);
            $locked->updated_by_user_id = $actor->id;
            $locked->save();

            CustomerCreditLog::create([
                'tenant_id' => $tenantId,
                'customer_id' => $customerId,
                'event_type' => 'TERMS_CHANGED',
                'old_values_json' => $oldTerms,
                'new_values_json' => [
                    'payment_terms_days' => $locked->payment_terms_days,
                    'grace_period_days' => $locked->grace_period_days,
                ],
                'actor_user_id' => $actor->id,
                'reason' => $reason,
            ]);

            return $locked;
        });
    }

    /**
     * Mengubah status kredit pelanggan (ACTIVE, ON_HOLD, SUSPENDED).
     */
    public function setCreditStatus(int $customerId, CreditStatusEnum $status, User $actor, string $reason): CustomerCreditAccount
    {
        $tenantId = (int) ($actor->tenant_id ?: $this->tenantContext->requireTenantId());

        return DB::transaction(function () use ($tenantId, $customerId, $status, $actor, $reason) {
            $customer = Customer::withoutGlobalScopes()->where('tenant_id', $tenantId)->findOrFail($customerId);
            Gate::forUser($actor)->authorize('manageCreditStatus', $customer);

            $account = $this->getOrCreateCreditAccount($customer, $tenantId);

            /** @var CustomerCreditAccount $locked */
            $locked = CustomerCreditAccount::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('id', $account->id)
                ->lockForUpdate()
                ->firstOrFail();

            $oldStatus = $locked->credit_status;
            $locked->credit_status = $status;
            $locked->updated_by_user_id = $actor->id;
            $locked->save();

            $eventType = $status->isBlocked() ? 'STATUS_HOLD' : 'STATUS_RELEASE';

            CustomerCreditLog::create([
                'tenant_id' => $tenantId,
                'customer_id' => $customerId,
                'event_type' => $eventType,
                'old_values_json' => ['credit_status' => $oldStatus->value],
                'new_values_json' => ['credit_status' => $status->value],
                'actor_user_id' => $actor->id,
                'reason' => $reason,
            ]);

            $this->auditTrailService->log(
                tenantId: $tenantId,
                action: 'credit.set_status',
                userId: $actor->id,
                storeId: null,
                subjectType: CustomerCreditAccount::class,
                subjectId: $locked->id,
                description: "Status kredit {$customer->name} diubah dari {$oldStatus->value} ke {$status->value}. Alasan: {$reason}",
                newValues: ['credit_status' => $status->value]
            );

            return $locked;
        });
    }
}
