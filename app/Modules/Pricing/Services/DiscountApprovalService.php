<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Services;

use App\Core\Context\TenantContext;
use App\Modules\Organization\Models\Approval;
use App\Modules\Organization\Models\User;
use App\Modules\Organization\Services\CashierPinService;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class DiscountApprovalService
{
    public const SAFE_DISCOUNT_PERCENTAGE = 5.0;

    public const SAFE_DISCOUNT_AMOUNT = 10000.0;

    public function __construct(
        protected CashierPinService $cashierPinService,
        protected TenantContext $tenantContext,
    ) {}

    /**
     * Mengevaluasi apakah diskon manual memerlukan otorisasi Supervisor.
     * Mengembalikan false jika dalam batas aman PRD OD-13 (<= 5% DAN <= Rp10.000).
     * Mengembalikan true jika melebihi batas aman (wajib approval).
     */
    public function evaluateThreshold(float $discountPercent, float $discountAmount, float $orderSubtotal = 0.0): bool
    {
        if ($discountPercent <= self::SAFE_DISCOUNT_PERCENTAGE && $discountAmount <= self::SAFE_DISCOUNT_AMOUNT) {
            return false;
        }

        return true;
    }

    /**
     * Membuat baris persetujuan baru di tabel `approvals` setelah memvalidasi PIN Supervisor
     * dan menegakkan pencegahan persetujuan mandiri (anti self-approval).
     *
     * @throws ValidationException
     */
    public function createApproval(
        int $requesterId,
        string $pin,
        string $action,
        float $amount,
        string $reason,
        int $storeId,
        ?int $approverId = null
    ): Approval {
        $tenantId = $this->tenantContext->requireTenantId();

        $requester = User::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('id', $requesterId)
            ->firstOrFail();

        $approver = null;

        if ($approverId !== null) {
            if ($requesterId === $approverId) {
                throw ValidationException::withMessages([
                    'pin' => 'Self-approval is prohibited. Users cannot approve discounts on their own transactions.',
                ]);
            }

            $approver = User::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('id', $approverId)
                ->first();

            if (! $approver || ! $this->cashierPinService->hasPin($approver) || ! $this->cashierPinService->verifyPin($approver, $pin)) {
                throw ValidationException::withMessages([
                    'pin' => 'Supervisor PIN is invalid or authorization failed.',
                ]);
            }
        } else {
            // Approver ID tidak ditentukan: Cari pengguna supervisor/manager/owner di tenant ini yang PIN-nya cocok
            $supervisors = User::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->whereHas('role', function ($query) {
                    $query->whereIn('name', ['Owner', 'Manager', 'Supervisor', 'Admin']);
                })
                ->whereNotNull('pin_hash')
                ->get();

            // Cek terlebih dahulu apakah requester mencoba self-approval dengan PIN-nya sendiri
            if ($requester->pin_hash && Hash::check($pin, (string) $requester->pin_hash)) {
                throw ValidationException::withMessages([
                    'pin' => 'Self-approval is prohibited. Users cannot approve discounts on their own transactions.',
                ]);
            }

            foreach ($supervisors as $candidate) {
                if ($candidate->id === $requesterId) {
                    continue; // Skip self
                }

                if ($this->cashierPinService->verifyPin($candidate, $pin)) {
                    $approver = $candidate;
                    break;
                }
            }

            if (! $approver) {
                throw ValidationException::withMessages([
                    'pin' => 'Supervisor PIN is invalid or lacks discount approval permission.',
                ]);
            }
        }

        return Approval::create([
            'tenant_id' => $tenantId,
            'store_id' => $storeId,
            'requester_user_id' => $requesterId,
            'approver_user_id' => $approver->id,
            'action_code' => $action,
            'amount' => $amount,
            'reason' => $reason,
            'status' => Approval::STATUS_APPROVED,
        ]);
    }

    /**
     * Validates order-level manual discount ceilings and Supervisor Approval records (P0-02).
     *
     * @throws ValidationException
     */
    public function validateOrderDiscountsAndApprovals(
        float $subtotalAmount,
        int $manualDiscount,
        float $approvalRequiredItemDiscountSum,
        ?int $approvalId,
        int $tenantId,
        ?int $storeId,
        int $actorUserId
    ): void {
        if ($manualDiscount < 0) {
            throw ValidationException::withMessages([
                'manual_discount_amount' => 'Manual discount cannot be negative.',
            ]);
        }

        if ($manualDiscount > $subtotalAmount) {
            throw ValidationException::withMessages([
                'manual_discount_amount' => 'Manual discount cannot exceed order subtotal.',
            ]);
        }

        $manualDiscountNeedsApproval = false;
        if ($manualDiscount > 0) {
            $manualPercent = $subtotalAmount > 0 ? (($manualDiscount / $subtotalAmount) * 100.0) : 100.0;
            $manualDiscountNeedsApproval = $this->evaluateThreshold($manualPercent, (float) $manualDiscount, $subtotalAmount);
        }

        $totalApprovalRequired = $approvalRequiredItemDiscountSum + ($manualDiscountNeedsApproval ? (float) $manualDiscount : 0.0);
        if ($totalApprovalRequired > 0.0) {
            if ($approvalId === null || $approvalId <= 0) {
                throw ValidationException::withMessages([
                    'approval_id' => 'Discount exceeds cashier safe threshold and requires Supervisor approval.',
                ]);
            }

            $this->assertValidApproval($approvalId, $tenantId, $storeId, $actorUserId, $totalApprovalRequired);
        } elseif ($approvalId !== null && $approvalId > 0) {
            $this->assertValidApproval($approvalId, $tenantId, $storeId, $actorUserId, (float) $manualDiscount);
        }
    }

    /**
     * Validates an existing Supervisor Approval record before applying an over-threshold discount or override.
     *
     * @throws ValidationException
     */
    public function assertValidApproval(
        int $approvalId,
        int $tenantId,
        ?int $storeId,
        int $actorUserId,
        float $requestedAmount,
        string $expectedAction = Approval::ACTION_MANUAL_DISCOUNT
    ): Approval {
        /** @var Approval|null $approval */
        $approval = $this->tenantContext->runAs($tenantId, $storeId, function () use ($tenantId, $approvalId) {
            return Approval::query()
                ->where('tenant_id', $tenantId)
                ->where('id', $approvalId)
                ->lockForUpdate()
                ->first();
        });

        if (! $approval) {
            throw ValidationException::withMessages([
                'approval_id' => 'Supervisor approval record was not found or belongs to another tenant.',
            ]);
        }

        if ($approval->status !== Approval::STATUS_APPROVED) {
            throw ValidationException::withMessages([
                'approval_id' => 'Supervisor approval token has already been used or is no longer active.',
            ]);
        }

        if ($approval->action_code !== $expectedAction) {
            throw ValidationException::withMessages([
                'approval_id' => "Supervisor approval action mismatch (expected {$expectedAction}).",
            ]);
        }

        if ($storeId !== null && (int) $approval->store_id > 0 && (int) $approval->store_id !== $storeId) {
            throw ValidationException::withMessages([
                'approval_id' => 'Supervisor approval is not valid for the active store branch.',
            ]);
        }

        if ((int) $approval->approver_user_id === $actorUserId) {
            throw ValidationException::withMessages([
                'approval_id' => 'Self-approval is prohibited. Cashiers cannot approve their own discounts.',
            ]);
        }

        if (bccomp((string) $approval->amount, number_format($requestedAmount, 2, '.', ''), 2) < 0) {
            throw ValidationException::withMessages([
                'approval_id' => 'Discount amount exceeds the Supervisor approved limit.',
            ]);
        }

        return $approval;
    }

    /**
     * Mengonsumsi token persetujuan saat transaksi selesai (atomic consumption).
     *
     * @throws DomainException
     */
    public function consumeApproval(int $approvalId, int $orderId): void
    {
        DB::transaction(function () use ($approvalId, $orderId) {
            /** @var Approval|null $approval */
            $approval = Approval::lockForUpdate()->find($approvalId);

            if (! $approval) {
                throw new DomainException("Approval request with ID #{$approvalId} was not found.");
            }

            if ($approval->status !== Approval::STATUS_APPROVED) {
                throw new DomainException("Approval token #{$approvalId} has already been used or is not in APPROVED status.");
            }

            $approval->update([
                'status' => Approval::STATUS_CONSUMED,
                'consumed_at' => now(),
                'consumed_by_order_id' => $orderId,
            ]);
        });
    }
}
