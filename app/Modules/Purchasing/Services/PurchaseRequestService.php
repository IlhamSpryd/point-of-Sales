<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Services;

use App\Enums\ApprovalStatusEnum;
use App\Enums\PurchaseRequestFulfillmentStatusEnum;
use App\Enums\PurchaseRequestStatusEnum;
use App\Models\Ingredient;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Modules\Purchasing\Models\PurchaseRequest;
use App\Modules\Purchasing\Models\PurchaseRequestLine;
use App\Modules\Settings\Services\AuditTrailService;
use App\Services\Context\TenantContext;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PurchaseRequestService
{
    public function __construct(
        protected DocumentSequenceService $sequenceService,
        protected PurchaseApprovalService $approvalService,
        protected AuditTrailService $auditTrailService,
        protected TenantContext $tenantContext
    ) {}

    /**
     * Membuat draf Permintaan Pembelian (PR) baru dengan baris item.
     *
     * @param  array<string, mixed>  $headerData
     * @param  array<int, array<string, mixed>>  $linesData
     */
    public function createPurchaseRequest(array $headerData, array $linesData, User $requester): PurchaseRequest
    {
        $tenantId = (int) $requester->tenant_id;
        $storeId = (int) ($headerData['store_id'] ?? $this->tenantContext->getStoreId());

        if ($storeId <= 0) {
            throw ValidationException::withMessages([
                'store_id' => ['Cabang tujuan (store_id) wajib ditentukan.'],
            ]);
        }

        // Validasi store milik tenant
        $store = Store::query()->where('tenant_id', $tenantId)->find($storeId);
        if (! $store) {
            throw ValidationException::withMessages([
                'store_id' => ['Cabang tidak ditemukan pada tenant ini.'],
            ]);
        }

        if (empty($linesData)) {
            throw ValidationException::withMessages([
                'lines' => ['Minimal harus ada 1 baris item dalam permintaan pembelian.'],
            ]);
        }

        return DB::transaction(function () use ($tenantId, $storeId, $headerData, $linesData, $requester) {
            $prNumber = ! empty($headerData['pr_number'])
                ? trim((string) $headerData['pr_number'])
                : $this->sequenceService->nextNumber($tenantId, 'PR', $storeId);

            $requiredByDate = ! empty($headerData['required_by_date'])
                ? Carbon::parse($headerData['required_by_date'])
                : now()->addDays(3);

            /** @var PurchaseRequest $pr */
            $pr = PurchaseRequest::query()->create([
                'tenant_id' => $tenantId,
                'store_id' => $storeId,
                'pr_number' => $prNumber,
                'requested_by_user_id' => $requester->id,
                'required_by_date' => $requiredByDate,
                'status' => PurchaseRequestStatusEnum::Draft,
                'fulfillment_status' => PurchaseRequestFulfillmentStatusEnum::Unfulfilled,
                'estimated_total_amount' => '0.00',
                'currency_code' => strtoupper(trim((string) ($headerData['currency_code'] ?? 'IDR'))),
                'notes' => ! empty($headerData['notes']) ? trim((string) $headerData['notes']) : null,
            ]);

            $totalEstimatedAmount = '0.00';

            foreach ($linesData as $index => $line) {
                $purchasableType = strtolower(trim((string) ($line['purchasable_type'] ?? '')));
                if (! in_array($purchasableType, ['product', 'ingredient'], true)) {
                    throw ValidationException::withMessages([
                        "lines.{$index}.purchasable_type" => ["Tipe item baris #{$index} harus 'product' atau 'ingredient'."],
                    ]);
                }

                $purchasableId = (int) ($line['purchasable_id'] ?? 0);
                if ($purchasableType === 'product') {
                    $item = Product::query()->where('tenant_id', $tenantId)->find($purchasableId);
                } else {
                    $item = Ingredient::query()->where('tenant_id', $tenantId)->find($purchasableId);
                }

                if (! $item) {
                    throw ValidationException::withMessages([
                        "lines.{$index}.purchasable_id" => ["Item {$purchasableType} #{$purchasableId} tidak ditemukan pada tenant ini."],
                    ]);
                }

                $requestedQty = (string) ($line['requested_qty'] ?? '0.0000');
                if (bccomp($requestedQty, '0', 4) <= 0) {
                    throw ValidationException::withMessages([
                        "lines.{$index}.requested_qty" => ['Kuantitas permintaan harus lebih besar dari 0.'],
                    ]);
                }

                $uom = trim((string) ($line['uom'] ?? ''));
                if ($uom === '') {
                    throw ValidationException::withMessages([
                        "lines.{$index}.uom" => ['Satuan UoM wajib diisi.'],
                    ]);
                }

                $unitPrice = (string) ($line['estimated_unit_price'] ?? '0.0000');
                if (bccomp($unitPrice, '0', 4) < 0) {
                    throw ValidationException::withMessages([
                        "lines.{$index}.estimated_unit_price" => ['Estimasi harga satuan tidak boleh negatif.'],
                    ]);
                }

                $subtotal = bcmul($requestedQty, $unitPrice, 2);
                $totalEstimatedAmount = bcadd($totalEstimatedAmount, $subtotal, 2);

                PurchaseRequestLine::query()->create([
                    'tenant_id' => $tenantId,
                    'purchase_request_id' => $pr->id,
                    'purchasable_type' => $purchasableType,
                    'purchasable_id' => $purchasableId,
                    'requested_qty' => $requestedQty,
                    'approved_qty' => $requestedQty,
                    'converted_to_po_qty' => '0.0000',
                    'uom' => $uom,
                    'estimated_unit_price' => $unitPrice,
                    'estimated_subtotal' => $subtotal,
                    'preferred_supplier_id' => ! empty($line['preferred_supplier_id']) ? (int) $line['preferred_supplier_id'] : null,
                    'notes' => ! empty($line['notes']) ? trim((string) $line['notes']) : null,
                ]);
            }

            $pr->estimated_total_amount = $totalEstimatedAmount;
            $pr->save();

            $this->auditTrailService->log(
                tenantId: $tenantId,
                action: 'pr.created',
                userId: $requester->id,
                storeId: $storeId,
                subjectType: PurchaseRequest::class,
                subjectId: (int) $pr->id,
                description: "Permintaan Pembelian {$pr->pr_number} dibuat dengan total estimasi Rp ".number_format((float) $totalEstimatedAmount, 2),
                newValues: [
                    'pr_number' => $pr->pr_number,
                    'estimated_total_amount' => $totalEstimatedAmount,
                    'status' => $pr->status->value,
                ]
            );

            return $pr->load('lines');
        });
    }

    /**
     * Memperbarui PR saat masih berstatus draf (penegakan imutabilitas jika sudah submitted).
     *
     * @param  array<string, mixed>  $headerData
     * @param  array<int, array<string, mixed>>  $linesData
     */
    public function updatePurchaseRequest(PurchaseRequest $pr, array $headerData, array $linesData, User $actor): PurchaseRequest
    {
        if (! $pr->isDraft()) {
            throw ValidationException::withMessages([
                'status' => ['Permintaan Pembelian yang telah diajukan bersifat immutable dan tidak dapat diubah.'],
            ]);
        }

        $tenantId = (int) $pr->tenant_id;

        return DB::transaction(function () use ($pr, $tenantId, $headerData, $linesData, $actor) {
            if (! empty($headerData['required_by_date'])) {
                $pr->required_by_date = Carbon::parse($headerData['required_by_date']);
            }
            if (array_key_exists('notes', $headerData)) {
                $pr->notes = ! empty($headerData['notes']) ? trim((string) $headerData['notes']) : null;
            }

            // Hapus baris lama dan buat ulang
            $pr->lines()->delete();

            $totalEstimatedAmount = '0.00';

            foreach ($linesData as $index => $line) {
                $purchasableType = strtolower(trim((string) ($line['purchasable_type'] ?? '')));
                $purchasableId = (int) ($line['purchasable_id'] ?? 0);
                $requestedQty = (string) ($line['requested_qty'] ?? '0.0000');
                $uom = trim((string) ($line['uom'] ?? ''));
                $unitPrice = (string) ($line['estimated_unit_price'] ?? '0.0000');

                $subtotal = bcmul($requestedQty, $unitPrice, 2);
                $totalEstimatedAmount = bcadd($totalEstimatedAmount, $subtotal, 2);

                PurchaseRequestLine::query()->create([
                    'tenant_id' => $tenantId,
                    'purchase_request_id' => $pr->id,
                    'purchasable_type' => $purchasableType,
                    'purchasable_id' => $purchasableId,
                    'requested_qty' => $requestedQty,
                    'approved_qty' => $requestedQty,
                    'converted_to_po_qty' => '0.0000',
                    'uom' => $uom,
                    'estimated_unit_price' => $unitPrice,
                    'estimated_subtotal' => $subtotal,
                    'preferred_supplier_id' => ! empty($line['preferred_supplier_id']) ? (int) $line['preferred_supplier_id'] : null,
                    'notes' => ! empty($line['notes']) ? trim((string) $line['notes']) : null,
                ]);
            }

            $pr->estimated_total_amount = $totalEstimatedAmount;
            $pr->save();

            $this->auditTrailService->log(
                tenantId: $tenantId,
                action: 'pr.updated',
                userId: $actor->id,
                storeId: (int) $pr->store_id,
                subjectType: PurchaseRequest::class,
                subjectId: (int) $pr->id,
                description: "Permintaan Pembelian {$pr->pr_number} diperbarui."
            );

            return $pr->load('lines');
        });
    }

    /**
     * Mengajukan PR ke alur persetujuan.
     */
    public function submitPurchaseRequest(PurchaseRequest $pr, User $submitter): PurchaseRequest
    {
        if (! $pr->isDraft()) {
            throw ValidationException::withMessages([
                'status' => ['Hanya PR berstatus draft yang dapat diajukan ke alur persetujuan.'],
            ]);
        }

        if ($pr->lines()->count() === 0) {
            throw ValidationException::withMessages([
                'lines' => ['PR tidak memiliki baris item untuk diajukan.'],
            ]);
        }

        return DB::transaction(function () use ($pr, $submitter) {
            $pr->status = PurchaseRequestStatusEnum::Submitted;
            $pr->save();

            // Mulai evaluasi mesin persetujuan
            $this->approvalService->initiateApproval($pr, $submitter);

            $this->auditTrailService->log(
                tenantId: (int) $pr->tenant_id,
                action: 'pr.submitted',
                userId: $submitter->id,
                storeId: (int) $pr->store_id,
                subjectType: PurchaseRequest::class,
                subjectId: (int) $pr->id,
                description: "Permintaan Pembelian {$pr->pr_number} diajukan ke alur persetujuan."
            );

            return $pr->fresh(['lines', 'approvalRequests']);
        });
    }

    /**
     * Membatalkan PR sebelum ada keputusan final.
     */
    public function cancelPurchaseRequest(PurchaseRequest $pr, User $actor, ?string $reason = null): PurchaseRequest
    {
        if (! $pr->canBeCancelled()) {
            throw ValidationException::withMessages([
                'status' => ['PR dengan status saat ini tidak dapat dibatalkan.'],
            ]);
        }

        return DB::transaction(function () use ($pr, $actor, $reason) {
            $pr->status = PurchaseRequestStatusEnum::Cancelled;
            if ($reason) {
                $pr->notes = $pr->notes ? $pr->notes."\n[Alasan Batal]: ".$reason : '[Alasan Batal]: '.$reason;
            }
            $pr->save();

            // Batalkan approval request yang sedang berjalan jika ada
            foreach ($pr->approvalRequests as $req) {
                if ($req->isPending()) {
                    $req->status = ApprovalStatusEnum::Cancelled;
                    $req->save();
                }
            }

            $this->auditTrailService->log(
                tenantId: (int) $pr->tenant_id,
                action: 'pr.cancelled',
                userId: $actor->id,
                storeId: (int) $pr->store_id,
                subjectType: PurchaseRequest::class,
                subjectId: (int) $pr->id,
                description: "Permintaan Pembelian {$pr->pr_number} dibatalkan.",
                metadata: ['reason' => $reason]
            );

            return $pr;
        });
    }

    /**
     * Membuka kembali PR yang ditolak agar dapat direvisi.
     */
    public function reopenRejectedPurchaseRequest(PurchaseRequest $pr, User $actor): PurchaseRequest
    {
        if ($pr->status !== PurchaseRequestStatusEnum::Rejected) {
            throw ValidationException::withMessages([
                'status' => ['Hanya PR berstatus ditolak yang dapat dibuka kembali untuk revisi.'],
            ]);
        }

        return DB::transaction(function () use ($pr, $actor) {
            $pr->status = PurchaseRequestStatusEnum::Draft;
            $pr->save();

            $this->auditTrailService->log(
                tenantId: (int) $pr->tenant_id,
                action: 'pr.reopened',
                userId: $actor->id,
                storeId: (int) $pr->store_id,
                subjectType: PurchaseRequest::class,
                subjectId: (int) $pr->id,
                description: "Permintaan Pembelian {$pr->pr_number} dibuka kembali untuk revisi."
            );

            return $pr;
        });
    }
}
