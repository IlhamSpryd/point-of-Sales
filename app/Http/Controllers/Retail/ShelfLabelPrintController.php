<?php

declare(strict_types=1);

namespace App\Http\Controllers\Retail;

use App\Http\Controllers\Controller;
use App\Modules\Retail\Models\ShelfLabel;
use App\Modules\Retail\Services\BarcodeSvgGenerator;
use App\Modules\Settings\Models\Store;
use App\Services\AuditTrailService;
use App\Services\Context\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ShelfLabelPrintController extends Controller
{
    public function __construct(
        protected BarcodeSvgGenerator $barcodeGenerator,
        protected AuditTrailService $auditTrail
    ) {}

    /**
     * Render the 60x30mm retail shelf label batch print page and mark the batch as PRINTED.
     */
    public function printBatch(Request $request, string $batch): View|RedirectResponse
    {
        $tenantId = (int) app(TenantContext::class)->getTenantId();
        if ($tenantId <= 0 && Auth::check()) {
            $tenantId = (int) Auth::user()->tenant_id;
        }

        $labels = ShelfLabel::with(['product', 'variant'])
            ->where('tenant_id', $tenantId)
            ->where('batch_number', $batch)
            ->get();

        if ($labels->isEmpty()) {
            return redirect()->route('retail.shelf-labels.index')
                ->with('error', "Batch label rak '{$batch}' tidak ditemukan.");
        }

        $storeId = $labels->first()->store_id;
        $store = Store::where('tenant_id', $tenantId)->find($storeId);
        $storeName = $store ? $store->name : 'Apeiron Retail Store';

        // Auto-mark batch as printed upon opening print sheet
        $userId = Auth::id() ? (int) Auth::id() : null;
        ShelfLabel::where('tenant_id', $tenantId)
            ->where('batch_number', $batch)
            ->where('status', ShelfLabel::STATUS_PENDING)
            ->update([
                'status' => ShelfLabel::STATUS_PRINTED,
                'printed_at' => now(),
                'printed_by_user_id' => $userId,
            ]);

        $this->auditTrail->log(
            tenantId: $tenantId,
            action: 'retail.shelf_labels_printed',
            userId: $userId,
            storeId: $storeId,
            subjectType: ShelfLabel::class,
            subjectId: $labels->first()->id,
            description: "Cetak fisik batch label rak {$batch} ({$labels->count()} label, layout 60x30mm).",
            metadata: [
                'batch_number' => $batch,
                'count' => $labels->count(),
            ]
        );

        $preparedLabels = $labels->map(function (ShelfLabel $lbl) {
            $product = $lbl->product;
            $variant = $lbl->variant;

            $productName = $product ? ($product->product_name ?? 'Produk Ritel') : 'Produk Ritel';
            if ($variant && ! empty($variant->variant_name)) {
                $productName .= ' ('.$variant->variant_name.')';
            }

            $sku = $variant ? $variant->sku : ($product ? $product->sku : '-');
            $barcode = $variant ? $variant->barcode : ($product ? $product->barcode : null);
            if (! $barcode) {
                $barcode = ($sku !== null && $sku !== '-') ? $sku : '899'.str_pad((string) $lbl->product_id, 9, '0', STR_PAD_LEFT);
            }

            $price = (float) $lbl->new_price;
            $barcodeSvg = $this->barcodeGenerator->renderSvg((string) $barcode, 36, 1.1);

            return [
                'id' => $lbl->id,
                'product_name' => $productName,
                'sku' => $sku,
                'barcode' => (string) $barcode,
                'barcode_svg' => $barcodeSvg,
                'price' => $price,
                'formatted_price' => 'Rp '.number_format($price, 0, ',', '.'),
                'old_price' => (float) $lbl->old_price,
                'is_discounted' => ((float) $lbl->old_price > $price),
                'batch_number' => $lbl->batch_number,
                'printed_at' => now()->format('d/m/Y H:i'),
            ];
        });

        return view('retail.shelf-labels.print', [
            'batchNumber' => $batch,
            'storeName' => $storeName,
            'labels' => $preparedLabels,
        ]);
    }
}
