<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Services;

use App\Enums\ApprovalStatusEnum;
use App\Models\Role;
use App\Models\User;
use App\Modules\Purchasing\Models\PurchaseApprovalLog;
use App\Modules\Purchasing\Models\PurchaseApprovalRequest;
use App\Modules\Purchasing\Models\PurchaseApprovalRule;
use App\Modules\Workflow\Exceptions\SelfApprovalForbiddenException;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApprovalEngineService
{
    public const LEVEL_1_BRANCH_MANAGER_MAX = '5000000.00';

    public const LEVEL_2_AREA_MANAGER_MAX = '25000000.00';

    public function __construct(
        protected SegregationOfDutiesGuard $sodGuard
    ) {}

    /**
     * Resolusi tingkat persetujuan berjenjang berdasarkan nominal dokumen:
     * - Level 1 (Branch Manager): <= Rp 5.000.000
     * - Level 2 (Area Manager): > Rp 5.000.000 s/d Rp 25.000.000
     * - Level 3 (Owner / Direktur): > Rp 25.000.000
     */
    public function resolveApprovalLevel(string|float|int $amount): int
    {
        $amountStr = is_numeric($amount) ? (string) $amount : '0.00';

        if (bccomp($amountStr, self::LEVEL_1_BRANCH_MANAGER_MAX, 2) <= 0) {
            return 1;
        }

        if (bccomp($amountStr, self::LEVEL_2_AREA_MANAGER_MAX, 2) <= 0) {
            return 2;
        }

        return 3;
    }

    /**
     * Resolusi label peran penyetuju berdasarkan level.
     */
    public function resolveRoleNameForLevel(int $level): string
    {
        return match ($level) {
            1 => 'Branch Manager',
            2 => 'Area Manager',
            default => 'Owner',
        };
    }

    /**
     * Mengajukan dokumen untuk persetujuan berjenjang sesuai matriks nominal.
     */
    public function submitRequest(
        Model $approvable,
        User $requester,
        string|float|int $amount,
        ?int $storeId = null,
        string $documentType = 'PR'
    ): PurchaseApprovalRequest {
        $tenantId = (int) $requester->tenant_id;
        $amountStr = is_numeric($amount) ? (string) $amount : '0.00';
        $level = $this->resolveApprovalLevel($amountStr);

        return DB::transaction(function () use ($approvable, $requester, $amountStr, $level, $storeId, $documentType, $tenantId) {
            // Cari atau buat rule persetujuan berjenjang untuk tenant ini
            $roleName = $this->resolveRoleNameForLevel($level);
            $role = Role::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('name', $roleName)
                ->first();

            // Canonical role fallback jika role 'Branch Manager' / 'Area Manager' tidak terdaftar pada tenant
            if (! $role) {
                $canonicalFallback = match ($level) {
                    1 => 'Manager',
                    2 => 'Manager',
                    default => 'Owner',
                };
                $role = Role::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->where('name', $canonicalFallback)
                    ->first()
                    ?? Role::withoutGlobalScopes()
                        ->where('tenant_id', $tenantId)
                        ->whereIn('name', ['Owner', 'Supervisor', 'Admin'])
                        ->first();
            }

            $minAmount = match ($level) {
                1 => '0.00',
                2 => bcadd(self::LEVEL_1_BRANCH_MANAGER_MAX, '0.01', 2),
                default => bcadd(self::LEVEL_2_AREA_MANAGER_MAX, '0.01', 2),
            };

            $maxAmount = match ($level) {
                1 => self::LEVEL_1_BRANCH_MANAGER_MAX,
                2 => self::LEVEL_2_AREA_MANAGER_MAX,
                default => null,
            };

            /** @var PurchaseApprovalRule $rule */
            $rule = PurchaseApprovalRule::withoutGlobalScopes()->firstOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'document_type' => $documentType,
                    'step_order' => $level,
                ],
                [
                    'store_id' => $storeId,
                    'currency_code' => 'IDR',
                    'min_amount' => $minAmount,
                    'max_amount' => $maxAmount,
                    'approver_role_id' => $role?->id,
                    'is_active' => true,
                ]
            );

            /** @var PurchaseApprovalRequest $request */
            $request = PurchaseApprovalRequest::withoutGlobalScopes()->create([
                'tenant_id' => $tenantId,
                'approvable_type' => $approvable->getMorphClass(),
                'approvable_id' => (int) $approvable->getKey(),
                'rule_id' => $rule->id,
                'step_order' => $level,
                'status' => ApprovalStatusEnum::Pending,
                'submitted_by_user_id' => $requester->id,
            ]);

            // Catat log pengajuan awal (Append-Only)
            PurchaseApprovalLog::withoutGlobalScopes()->create([
                'tenant_id' => $tenantId,
                'approval_request_id' => $request->id,
                'approvable_type' => $approvable->getMorphClass(),
                'approvable_id' => (int) $approvable->getKey(),
                'action' => 'submitted',
                'actor_id' => $requester->id,
                'actor_role_id' => $requester->role_id,
                'step_order' => $level,
                'reason' => "Pengajuan dokumen senilai Rp {$amountStr} dialihkan ke antrean persetujuan Level {$level} ({$roleName}).",
                'payload_snapshot' => [
                    'amount' => $amountStr,
                    'level' => $level,
                    'rule_id' => $rule->id,
                    'document_type' => $documentType,
                ],
            ]);

            return $request;
        });
    }

    /**
     * Memproses persetujuan dokumen oleh pejabat berwenang.
     * Mencegah Self-Approval secara mutlak via SegregationOfDutiesGuard.
     *
     * @throws SelfApprovalForbiddenException
     * @throws ValidationException
     */
    public function approve(PurchaseApprovalRequest $request, User $approver, ?string $notes = null): bool
    {
        // Penegakan Pemisahan Tugas (SoD)
        $this->sodGuard->assertCanApprove((int) $request->submitted_by_user_id, (int) $approver->id);

        $tenantId = (int) $request->tenant_id;

        return DB::transaction(function () use ($request, $approver, $notes, $tenantId) {
            /** @var PurchaseApprovalRequest $lockedRequest */
            $lockedRequest = PurchaseApprovalRequest::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->findOrFail($request->id);

            if (! $lockedRequest->isPending()) {
                throw new DomainException('Permintaan persetujuan sudah tidak dalam status pending.');
            }

            $lockedRequest->update([
                'status' => ApprovalStatusEnum::Approved,
                'approved_by_user_id' => $approver->id,
                'decided_at' => now(),
            ]);

            // Catat log persetujuan (Append-Only)
            PurchaseApprovalLog::withoutGlobalScopes()->create([
                'tenant_id' => $tenantId,
                'approval_request_id' => $lockedRequest->id,
                'approvable_type' => $lockedRequest->approvable_type,
                'approvable_id' => $lockedRequest->approvable_id,
                'action' => 'approved',
                'actor_id' => $approver->id,
                'actor_role_id' => $approver->role_id,
                'step_order' => $lockedRequest->step_order,
                'reason' => $notes ?? 'Persetujuan disahkan oleh pejabat berwenang.',
                'payload_snapshot' => [
                    'approved_at' => now()->toIso8601String(),
                    'approver_name' => $approver->name,
                ],
            ]);

            return true;
        });
    }

    /**
     * Menolak dokumen persetujuan dengan alasan penolakan wajib.
     *
     * @throws SelfApprovalForbiddenException
     * @throws ValidationException
     */
    public function reject(PurchaseApprovalRequest $request, User $approver, string $reason): bool
    {
        // Penegakan Pemisahan Tugas (SoD)
        $this->sodGuard->assertCanApprove((int) $request->submitted_by_user_id, (int) $approver->id);

        $trimmedReason = trim($reason);
        if ($trimmedReason === '') {
            throw ValidationException::withMessages([
                'reason' => ['Alasan penolakan dokumen wajib dicantumkan.'],
            ]);
        }

        $tenantId = (int) $request->tenant_id;

        return DB::transaction(function () use ($request, $approver, $trimmedReason, $tenantId) {
            /** @var PurchaseApprovalRequest $lockedRequest */
            $lockedRequest = PurchaseApprovalRequest::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->findOrFail($request->id);

            if (! $lockedRequest->isPending()) {
                throw new DomainException('Permintaan persetujuan sudah tidak dalam status pending.');
            }

            $lockedRequest->update([
                'status' => ApprovalStatusEnum::Rejected,
                'approved_by_user_id' => $approver->id,
                'rejection_reason' => $trimmedReason,
                'decided_at' => now(),
            ]);

            // Catat log penolakan (Append-Only)
            PurchaseApprovalLog::withoutGlobalScopes()->create([
                'tenant_id' => $tenantId,
                'approval_request_id' => $lockedRequest->id,
                'approvable_type' => $lockedRequest->approvable_type,
                'approvable_id' => $lockedRequest->approvable_id,
                'action' => 'rejected',
                'actor_id' => $approver->id,
                'actor_role_id' => $approver->role_id,
                'step_order' => $lockedRequest->step_order,
                'reason' => $trimmedReason,
                'payload_snapshot' => [
                    'rejected_at' => now()->toIso8601String(),
                    'approver_name' => $approver->name,
                ],
            ]);

            return true;
        });
    }
}
