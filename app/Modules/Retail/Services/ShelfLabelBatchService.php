<?php

declare(strict_types=1);

namespace App\Modules\Retail\Services;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Modules\Retail\Models\ShelfLabel;
use App\Services\AuditTrailService;
use App\Services\Context\TenantContext;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ShelfLabelBatchService
{
    public function __construct(
        protected AuditTrailService $auditTrail,
        protected TenantContext $tenantContext
    ) {}

    /**
     * Menambahkan produk/varian ke dalam antrean cetak label harga rak (SHELF_LABEL_PENDING_PRINT).
     */
    public function markProductPendingPrint(
        int $tenantId,
        int $storeId,
        int $productId,
        ?int $variantId = null,
        ?float $oldPrice = null,
        ?float $newPrice = null
    ): ShelfLabel {
        $product = Product::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('id', $productId)
            ->first();

        if (! $product) {
            throw new DomainException('Produk tidak ditemukan.');
        }

        $resolvedNewPrice = $newPrice ?? (float) $product->product_price;
        $resolvedOldPrice = $oldPrice ?? $resolvedNewPrice;

        if ($variantId !== null) {
            $variant = ProductVariant::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('product_id', $productId)
                ->where('id', $variantId)
                ->first();

            $variantPrice = $variant ? ($variant->price_override ?? $variant->price ?? null) : null;
            if ($variantPrice !== null) {
                $resolvedNewPrice = $newPrice ?? (float) $variantPrice;
            }
        }

        return DB::transaction(function () use (
            $tenantId, $storeId, $productId, $variantId, $resolvedOldPrice, $resolvedNewPrice
        ) {
            $label = ShelfLabel::create([
                'tenant_id' => $tenantId,
                'store_id' => $storeId,
                'product_id' => $productId,
                'product_variant_id' => $variantId,
                'old_price' => $resolvedOldPrice,
                'new_price' => $resolvedNewPrice,
                'status' => ShelfLabel::STATUS_PENDING,
            ]);

            $currentAuthId = auth()->id();
            $this->auditTrail->log(
                tenantId: $tenantId,
                action: 'retail.shelf_label_queued',
                userId: $currentAuthId !== null ? (int) $currentAuthId : null,
                storeId: $storeId,
                subjectType: ShelfLabel::class,
                subjectId: $label->id,
                description: "Label rak produk #{$productId} masuk antrean cetak harga (Rp ".number_format($resolvedNewPrice, 0, ',', '.').').',
                metadata: [
                    'label_id' => $label->id,
                    'old_price' => $resolvedOldPrice,
                    'new_price' => $resolvedNewPrice,
                ]
            );

            return $label;
        });
    }

    /**
     * Mengambil daftar label rak yang berstatus SHELF_LABEL_PENDING_PRINT.
     *
     * @return array<int, array{id: int, product_name: string, sku: string, barcode: string, old_price: float, new_price: float, created_at: string}>
     */
    public function getPendingLabels(int $tenantId, int $storeId): array
    {
        $labels = ShelfLabel::where('tenant_id', $tenantId)
            ->where('store_id', $storeId)
            ->where('status', ShelfLabel::STATUS_PENDING)
            ->with(['product', 'variant'])
            ->latest('id')
            ->get();

        $results = [];
        foreach ($labels as $lbl) {
            $product = $lbl->product;
            $variant = $lbl->variant;

            $productName = $product instanceof Product ? $product->product_name : 'Produk';
            if ($variant instanceof ProductVariant && ! empty($variant->variant_name)) {
                $productName .= ' - '.$variant->variant_name;
            }

            $sku = ($variant instanceof ProductVariant && $variant->sku)
                ? $variant->sku
                : (($product instanceof Product && $product->sku) ? $product->sku : '-');
            $barcode = ($variant instanceof ProductVariant && $variant->barcode)
                ? $variant->barcode
                : (($product instanceof Product && $product->barcode) ? $product->barcode : '-');

            $results[] = [
                'id' => (int) $lbl->id,
                'product_name' => $productName,
                'sku' => (string) $sku,
                'barcode' => (string) $barcode,
                'old_price' => (float) $lbl->old_price,
                'new_price' => (float) $lbl->new_price,
                'created_at' => $lbl->created_at?->toIso8601String() ?? now()->toIso8601String(),
            ];
        }

        return $results;
    }

    /**
     * Menghasilkan batch cetak label harga dan mengembalikan data yang siap dikirim ke printer label rak.
     *
     * @param  array<int>|null  $labelIds
     * @return array{batch_number: string, count: int, labels: array<int, array<string, mixed>>}
     */
    public function generatePrintBatch(int $tenantId, int $storeId, ?array $labelIds = null, ?int $userId = null): array
    {
        $batchNumber = 'LBL-'.date('Ymd').'-'.strtoupper(Str::random(6));

        /** @var array{batch_number: string, count: int, labels: array<int, array<string, mixed>>} $result */
        $result = DB::transaction(function () use ($tenantId, $storeId, $labelIds, $userId, $batchNumber): array {
            $query = ShelfLabel::where('tenant_id', $tenantId)
                ->where('store_id', $storeId)
                ->where('status', ShelfLabel::STATUS_PENDING)
                ->lockForUpdate();

            if (! empty($labelIds)) {
                $query->whereIn('id', $labelIds);
            }

            $labels = $query->with(['product', 'variant'])->get();

            if ($labels->isEmpty()) {
                throw new DomainException('Tidak ada label harga rak pending untuk dicetak.');
            }

            $labelData = [];
            foreach ($labels as $lbl) {
                $lbl->update([
                    'batch_number' => $batchNumber,
                    'status' => ShelfLabel::STATUS_PRINTED,
                    'printed_at' => now(),
                    'printed_by_user_id' => $userId,
                ]);

                $product = $lbl->product;
                $variant = $lbl->variant;

                $productName = $product instanceof Product ? $product->product_name : 'Produk';
                if ($variant instanceof ProductVariant && ! empty($variant->variant_name)) {
                    $productName .= ' - '.$variant->variant_name;
                }

                $sku = ($variant instanceof ProductVariant && $variant->sku)
                    ? $variant->sku
                    : (($product instanceof Product && $product->sku) ? $product->sku : '');
                $barcode = ($variant instanceof ProductVariant && $variant->barcode)
                    ? $variant->barcode
                    : (($product instanceof Product && $product->barcode) ? $product->barcode : '');

                $labelData[] = [
                    'label_id' => $lbl->id,
                    'product_name' => $productName,
                    'barcode' => $barcode,
                    'sku' => $sku,
                    'price' => (float) $lbl->new_price,
                ];
            }

            $firstLabel = $labels->first();
            $this->auditTrail->log(
                tenantId: $tenantId,
                action: 'retail.shelf_labels_printed',
                userId: $userId,
                storeId: $storeId,
                subjectType: ShelfLabel::class,
                subjectId: $firstLabel->id,
                description: "Batch cetak label rak {$batchNumber} dihasilkan ({$labels->count()} label).",
                metadata: [
                    'batch_number' => $batchNumber,
                    'count' => $labels->count(),
                ]
            );

            return [
                'batch_number' => $batchNumber,
                'count' => count($labelData),
                'labels' => $labelData,
            ];
        });

        return $result;
    }

    /**
     * Menandai sejumlah label rak telah selesai dicetak secara manual.
     *
     * @param  array<int>  $labelIds
     */
    public function markLabelsPrinted(int $tenantId, int $storeId, array $labelIds, int $userId): void
    {
        if (empty($labelIds)) {
            return;
        }

        DB::transaction(function () use ($tenantId, $storeId, $labelIds, $userId) {
            ShelfLabel::where('tenant_id', $tenantId)
                ->where('store_id', $storeId)
                ->whereIn('id', $labelIds)
                ->update([
                    'status' => ShelfLabel::STATUS_PRINTED,
                    'printed_at' => now(),
                    'printed_by_user_id' => $userId,
                    'updated_at' => now(),
                ]);
        });
    }
}
