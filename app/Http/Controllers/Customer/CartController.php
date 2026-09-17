<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\AddToCartRequest;
use App\Models\Product;
use App\Services\CartService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * CartController: Thin Controller — seluruh logika berat didelegasikan
 * ke CartService, mengikuti Service Pattern yang sudah baku di proyek ini.
 */
class CartController extends Controller
{
    public function __construct(protected CartService $cartService) {}

    /**
     * Menampilkan halaman keranjang / ringkasan sebelum checkout.
     */
    public function index(): View
    {
        $items = $this->cartService->getItems();
        $subtotal = $this->cartService->getSubtotal();

        return view('customer.cart.index', compact('items', 'subtotal'));
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
