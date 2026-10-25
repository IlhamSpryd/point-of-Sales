<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Services;

use App\Enums\ApprovalStatusEnum;
use App\Enums\PurchaseOrderStatusEnum;
use App\Enums\PurchaseRequestStatusEnum;
use App\Models\User;
use App\Modules\Purchasing\Models\PurchaseApprovalLog;
use App\Modules\Purchasing\Models\PurchaseApprovalRequest;
use App\Modules\Purchasing\Models\PurchaseApprovalRule;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseRequest;
use App\Modules\Settings\Services\AuditTrailService;
use App\Services\Context\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PurchaseApprovalService
{
    public function __construct(
        protected AuditTrailService $auditTrailService,
        protected TenantContext $tenantContext
    ) {}

    /**
     * Konfigurasi aturan persetujuan (PurchaseApprovalRule).
     *
     * @param  array<string, mixed>  $data
     */
    public function createOrUpdateRule(array $data, ?User $actor = null): PurchaseApprovalRule
    {
        $tenantId = (int) ($actor ? $actor->tenant_id : $this->tenantContext->requireTenantId());

        $minAmount = (string) ($data['min_amount'] ?? '0.00');
        if (bccomp($minAmount, '0', 2) < 0) {
            throw ValidationException::withMessages([
                'min_amount' => ['Batas minimal nominal persetujuan tidak boleh negatif.'],
            ]);
        }

        $maxAmount = isset($data['max_amount']) && $data['max_amount'] !== '' ? (string) $data['max_amount'] : null;
        if ($maxAmount !== null && bccomp($maxAmount, $minAmount, 2) < 0) {
            throw ValidationException::withMessages([
                'max_amount' => ['Batas maksimal nominal harus lebih besar atau sama dengan minimal nominal.'],
            ]);
        }

        $ruleId = isset($data['id']) ? (int) $data['id'] : null;

        return DB::transaction(function () use ($ruleId, $tenantId, $data, $minAmount, $maxAmount) {
            $payload = [
                'tenant_id' => $tenantId,
                'store_id' => ! empty($data['store_id']) ? (int) $data['store_id'] : null,
                'document_type' => strtoupper(trim((string) ($data['document_type'] ?? 'PR'))),
                'currency_code' => strtoupper(trim((string) ($data['currency_code'] ?? 'IDR'))),
                'min_amount' => $minAmount,
                'max_amount' => $maxAmount,
                'step_order' => max(1, (int) ($data['step_order'] ?? 1)),
                'approver_role_id' => ! empty($data['approver_role_id']) ? (int) $data['approver_role_id'] : null,
                'approver_permission' => ! empty($data['approver_permission']) ? trim((string) $data['approver_permission']) : null,
                'is_active' => (bool) ($data['is_active'] ?? true),
            ];

            if ($ruleId) {
                /** @var PurchaseApprovalRule $rule */
                $rule = PurchaseApprovalRule::query()
                    ->where('tenant_id', $tenantId)
                    ->findOrFail($ruleId);
                $rule->update($payload);
            } else {
                /** @var PurchaseApprovalRule $rule */
                $rule = PurchaseApprovalRule::query()->create($payload);
            }

            return $rule;
        });
    }

    /**
     * Memulai alur persetujuan bertingkat untuk dokumen tertentu.
     */
    public function initiateApproval(Model $approvable, User $submitter): ?PurchaseApprovalRequest
    {
        $tenantId = (int) $approvable->getAttribute('tenant_id');
        $storeId = $approvable->getAttribute('store_id');
        $documentType = $this->resolveDocumentType($approvable);
        $amount = (string) ($approvable->getAttribute('estimated_total_amount') ?? $approvable->getAttribute('total_amount') ?? '0.00');

        // Cari aturan aktif yang relevan
        $rules = PurchaseApprovalRule::query()
            ->where('tenant_id', $tenantId)
            ->where('document_type', $documentType)
            ->where('is_active', true)
            ->where(function ($q) use ($storeId) {
                $q->whereNull('store_id');
                if ($storeId) {
                    $q->orWhere('store_id', $storeId);
                }
            })
            ->orderBy('step_order', 'asc')
            ->get();

        $matchedRules = $rules->filter(fn (PurchaseApprovalRule $r) => $r->matchesAmount($amount));

        // Jika tidak ada aturan yang berlaku: dokumen langsung disetujui (auto-approve)
        if ($matchedRules->isEmpty()) {
            $this->finalizeDocumentApproval($approvable, null, 'Otomatis disetujui karena tidak ada aturan ambang batas.');

            return null;
        }

        // Ambil aturan langkah pertama
        /** @var PurchaseApprovalRule $firstRule */
        $firstRule = $matchedRules->first();

        /** @var PurchaseApprovalRequest $approvalRequest */
        $approvalRequest = PurchaseApprovalRequest::query()->create([
            'tenant_id' => $tenantId,
            'approvable_type' => $approvable->getMorphClass(),
            'approvable_id' => $approvable->getKey(),
            'rule_id' => $firstRule->id,
            'step_order' => $firstRule->step_order,
            'status' => ApprovalStatusEnum::Pending,
            'submitted_by_user_id' => $submitter->id,
        ]);

        // Catat log pengajuan awal (Append-Only)
        PurchaseApprovalLog::query()->create([
            'tenant_id' => $tenantId,
            'approval_request_id' => $approvalRequest->id,
            'approvable_type' => $approvable->getMorphClass(),
            'approvable_id' => $approvable->getKey(),
            'action' => 'submitted',
            'actor_id' => $submitter->id,
            'actor_role_id' => $submitter->role_id,
            'step_order' => $firstRule->step_order,
            'reason' => 'Pengajuan dokumen ke alur persetujuan langkah '.$firstRule->step_order,
            'payload_snapshot' => [
                'document_type' => $documentType,
                'amount' => $amount,
                'rule_id' => $firstRule->id,
            ],
        ]);

        return $approvalRequest;
    }

    /**
     * Memproses persetujuan dokumen oleh pejabat berwenang dengan penegakan SoD.
     */
    public function approve(PurchaseApprovalRequest $request, User $approver, ?string $notes = null): bool
    {
        $tenantId = (int) $request->tenant_id;

        return DB::transaction(function () use ($request, $approver, $notes, $tenantId) {
            /** @var PurchaseApprovalRequest $lockedRequest */
            $lockedRequest = PurchaseApprovalRequest::query()
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->findOrFail($request->id);

            if (! $lockedRequest->isPending()) {
                throw new \RuntimeException('Permintaan persetujuan sudah tidak dalam status pending.');
            }

            // Penegakan Pemisahan Tugas (Separation of Duties / SoD)
            $isSoleUser = User::query()->where('tenant_id', $tenantId)->where('is_active', true)->count() === 1;
            if ((int) $approver->id === (int) $lockedRequest->submitted_by_user_id && ! $isSoleUser) {
                throw ValidationException::withMessages([
                    'approver' => ['Pemisahan Tugas (SoD): Anda dilarang menyetujui dokumen yang Anda ajukan sendiri.'],
                ]);
            }

            // Verifikasi izin atau peran persetujuan
            $rule = $lockedRequest->rule;
            if ($rule) {
                if ($rule->approver_role_id && (int) $approver->role_id !== (int) $rule->approver_role_id) {
                    $roleName = $approver->role?->name;
                    if ($roleName !== 'Owner' && ! (bool) $approver->role?->is_admin) {
                        throw ValidationException::withMessages([
                            'approver' => ['Peran Anda tidak memiliki wewenang untuk menyetujui langkah ini.'],
                        ]);
                    }
                }

                if ($rule->approver_permission && ! $approver->hasPermission($rule->approver_permission)) {
                    if ($approver->role?->name !== 'Owner' && ! (bool) $approver->role?->is_admin) {
                        throw ValidationException::withMessages([
                            'approver' => ["Anda tidak memiliki izin '{$rule->approver_permission}' untuk menyetujui langkah ini."],
                        ]);
                    }
                }
            }

            $currentStep = $lockedRequest->step_order;

            // Catat log keputusan disetujui (Append-only)
            PurchaseApprovalLog::query()->create([
                'tenant_id' => $tenantId,
                'approval_request_id' => $lockedRequest->id,
                'approvable_type' => $lockedRequest->approvable_type,
                'approvable_id' => $lockedRequest->approvable_id,
                'action' => 'approved',
                'actor_id' => $approver->id,
                'actor_role_id' => $approver->role_id,
                'step_order' => $currentStep,
                'reason' => $notes,
            ]);

            // Cek apakah ada langkah persetujuan selanjutnya
            $approvable = $lockedRequest->approvable;
            $documentType = $this->resolveDocumentType($approvable);
            $amount = (string) ($approvable->getAttribute('estimated_total_amount') ?? $approvable->getAttribute('total_amount') ?? '0.00');
            $storeId = $approvable->getAttribute('store_id');

            $nextRule = PurchaseApprovalRule::query()
                ->where('tenant_id', $tenantId)
                ->where('document_type', $documentType)
                ->where('is_active', true)
                ->where('step_order', '>', $currentStep)
                ->where(function ($q) use ($storeId) {
                    $q->whereNull('store_id');
                    if ($storeId) {
                        $q->orWhere('store_id', $storeId);
                    }
                })
                ->orderBy('step_order', 'asc')
                ->get()
                ->first(fn (PurchaseApprovalRule $r) => $r->matchesAmount($amount));

            if ($nextRule) {
                // Maju ke langkah berikutnya
                $lockedRequest->step_order = $nextRule->step_order;
                $lockedRequest->rule_id = $nextRule->id;
                $lockedRequest->save();
            } else {
                // Seluruh langkah selesai: dokumen disetujui penuh
                $lockedRequest->status = ApprovalStatusEnum::Approved;
                $lockedRequest->approved_by_user_id = $approver->id;
                $lockedRequest->decided_at = now();
                $lockedRequest->save();

                $this->finalizeDocumentApproval($approvable, $approver, $notes);
            }

            return true;
        });
    }

    /**
     * Memproses penolakan dokumen dengan alasan eksplisit wajib (min 10 karakter).
     */
    public function reject(PurchaseApprovalRequest $request, User $rejector, string $reason): bool
    {
        $trimmedReason = trim($reason);
        if (mb_strlen($trimmedReason) < 10) {
            throw ValidationException::withMessages([
                'reason' => ['Alasan penolakan wajib diisi minimal 10 karakter.'],
            ]);
        }

        $tenantId = (int) $request->tenant_id;

        return DB::transaction(function () use ($request, $rejector, $trimmedReason, $tenantId) {
            /** @var PurchaseApprovalRequest $lockedRequest */
            $lockedRequest = PurchaseApprovalRequest::query()
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->findOrFail($request->id);

            if (! $lockedRequest->isPending()) {
                throw new \RuntimeException('Permintaan persetujuan sudah tidak dalam status pending.');
            }

            // Catat log penolakan (Append-only)
            PurchaseApprovalLog::query()->create([
                'tenant_id' => $tenantId,
                'approval_request_id' => $lockedRequest->id,
                'approvable_type' => $lockedRequest->approvable_type,
                'approvable_id' => $lockedRequest->approvable_id,
                'action' => 'rejected',
                'actor_id' => $rejector->id,
                'actor_role_id' => $rejector->role_id,
                'step_order' => $lockedRequest->step_order,
                'reason' => $trimmedReason,
            ]);

            $lockedRequest->status = ApprovalStatusEnum::Rejected;
            $lockedRequest->approved_by_user_id = $rejector->id;
            $lockedRequest->rejection_reason = $trimmedReason;
            $lockedRequest->decided_at = now();
            $lockedRequest->save();

            // Ubah status dokumen induk menjadi rejected
            $approvable = $lockedRequest->approvable;
            if ($approvable instanceof PurchaseRequest) {
                $approvable->status = PurchaseRequestStatusEnum::Rejected;
                $approvable->rejection_reason = $trimmedReason;
                $approvable->save();
            } elseif ($approvable instanceof PurchaseOrder) {
                $approvable->status = PurchaseOrderStatusEnum::Rejected;
                $approvable->cancellation_reason = $trimmedReason;
                $approvable->save();
            }

            $this->auditTrailService->log(
                tenantId: $tenantId,
                action: 'approval.rejected',
                userId: $rejector->id,
                subjectType: $lockedRequest->approvable_type,
                subjectId: (int) $lockedRequest->approvable_id,
                description: 'Dokumen ditolak pada alur persetujuan.',
                metadata: ['reason' => $trimmedReason]
            );

            return true;
        });
    }

    private function finalizeDocumentApproval(Model $approvable, ?User $approver, ?string $notes = null): void
    {
        $tenantId = (int) $approvable->getAttribute('tenant_id');

        if ($approvable instanceof PurchaseRequest) {
            $approvable->status = PurchaseRequestStatusEnum::Approved;
            $approvable->approved_at = now();
            $approvable->approved_by_user_id = $approver?->id;
            $approvable->save();

            // Set approved_qty sama dengan requested_qty jika belum diset
            foreach ($approvable->lines as $line) {
                if (bccomp((string) $line->approved_qty, '0', 4) <= 0) {
                    $line->approved_qty = $line->requested_qty;
                    $line->save();
                }
            }
        } elseif ($approvable instanceof PurchaseOrder) {
            $approvable->status = PurchaseOrderStatusEnum::Approved;
            $approvable->approved_at = now();
            $approvable->approved_by_user_id = $approver?->id;
            $approvable->save();
        }

        $this->auditTrailService->log(
            tenantId: $tenantId,
            action: 'approval.approved',
            userId: $approver?->id,
            subjectType: $approvable->getMorphClass(),
            subjectId: (int) $approvable->getKey(),
            description: 'Dokumen berhasil disetujui penuh.',
            metadata: ['notes' => $notes]
        );
    }

    private function resolveDocumentType(Model $approvable): string
    {
        if ($approvable instanceof PurchaseRequest) {
            return 'PR';
        }

        if ($approvable instanceof PurchaseOrder) {
            return 'PO';
        }

        return 'PR';
    }
}
