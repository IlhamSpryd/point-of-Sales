<?php

namespace App\Modules\Customers\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\AddToCartRequest;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Table;
use App\Services\CartService;
use App\Services\Context\TenantContext;
use App\Services\TransactionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * CartController: Thin Controller — seluruh logika berat didelegasikan
 * ke CartService dan TransactionService.
 */
class CartController extends Controller
{
    public function __construct(
        protected CartService $cartService,
        protected TransactionService $transactionService,
    ) {}

    /**
     * Menampilkan halaman keranjang / ringkasan sebelum checkout.
     */
    public function index(): View
    {
        $items = $this->cartService->getItems();
        $subtotal = $this->cartService->getSubtotal();
        $totals = $this->transactionService->calculateOrderTotals($subtotal);

        $tableId = session('current_table_id');
        $currentTable = null;
        if ($tableId) {
            $currentTable = Table::withoutGlobalScopes()
                ->where('id', $tableId)
                ->where('tenant_id', app(TenantContext::class)->getTenantId())
                ->first();
        }
        $menuUrl = $currentTable ? route('customer.menu.index', ['token' => $currentTable->secure_token]) : null;

        $taxAmount = $totals['tax_amount'];
        $totalAmount = $totals['total_amount'];
        $taxRate = (float) Setting::get('tax_rate', config('pos.tax_rate', 0.11));
        $roundingValue = (int) Setting::get('rounding_value', config('pos.rounding_value', 100));
        $roundingBehavior = (string) Setting::get('rounding_behavior', config('pos.rounding_behavior', 'ROUND_NEAREST'));

        return view('customer.cart.index', compact(
            'items', 'subtotal', 'taxAmount', 'totalAmount', 'currentTable', 'menuUrl',
            'taxRate', 'roundingValue', 'roundingBehavior'
        ));
    }

    /**
     * Menambahkan produk ke keranjang. Dipanggil via fetch() dari modal varian.
     * Selalu mengembalikan JSON karena dipanggil secara asynchronous oleh Alpine.js.
     */
    public function store(AddToCartRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $product = Product::findOrFail($validated['product_id']);

        $item = $this->cartService->addItem(
            $product,
            $validated['modifier_ids'] ?? [],
            $validated['qty'],
            $validated['notes'] ?? null
        );

        return response()->json([
            'success' => true,
            'message' => 'Item ditambahkan ke keranjang.',
            'item' => $item,
            'total_qty' => $this->cartService->getTotalQty(),
            'subtotal' => $this->cartService->getSubtotal(),
        ]);
    }

    /**
     * Update quantity satu baris keranjang (tombol +/- di halaman cart).
     */
    public function update(Request $request, string $lineId): JsonResponse
    {
        $request->validate(['qty' => ['required', 'integer', 'min:0', 'max:20']]);

        $this->cartService->updateQty($lineId, (int) $request->qty);

        return response()->json([
            'success' => true,
            'total_qty' => $this->cartService->getTotalQty(),
            'subtotal' => $this->cartService->getSubtotal(),
        ]);
    }

    /**
     * Menghapus satu baris keranjang secara permanen.
     */
    public function destroy(string $lineId): JsonResponse
    {
        $this->cartService->removeItem($lineId);

        return response()->json([
            'success' => true,
            'total_qty' => $this->cartService->getTotalQty(),
            'subtotal' => $this->cartService->getSubtotal(),
        ]);
    }
}
