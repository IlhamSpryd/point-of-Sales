<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

/**
 * MenuController: Melayani sisi pelanggan (customer self-order).
 * Berbeda dengan ProductController (admin), controller ini HANYA read-only
 * dan tidak pernah menyentuh proses create/update/delete produk.
 */
class MenuController extends Controller
{
    /**
     * Menampilkan katalog menu untuk pelanggan.
     * Hanya produk aktif (is_active) yang ditampilkan, sama seperti aturan
     * di TransactionController::create() milik kasir — produk nonaktif
     * tidak boleh terlihat sama sekali oleh siapa pun di luar admin.
     */
    public function index(): View
    {
        $categories = Category::all();

        $products = Product::where('is_active', true)
            ->with(['category', 'modifierGroups.modifiers'])
            ->get();

        return view('customer.menu.index', compact('categories', 'products'));
    }

    /**
     * Mengembalikan data grup varian + opsi untuk satu produk dalam format JSON.
     * Dipanggil oleh frontend (Alpine.js fetch) saat pelanggan membuka modal
     * pilihan varian pada sebuah produk, contoh: pilih Suhu, Gula, Susu.
     */
    public function modifiers(Product $product): JsonResponse
    {
        // Jaga-jaga: tolak akses ke produk yang sudah dinonaktifkan admin,
        // walaupun pelanggan tahu ID produknya secara langsung dari URL.
        if (! $product->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'Produk ini sudah tidak tersedia.',
            ], 404);
        }

        $product->load('modifierGroups.modifiers');

        return response()->json([
            'success' => true,
            'product' => [
                'id' => $product->id,
                'name' => $product->product_name,
                'price' => $product->product_price,
            ],
            'modifier_groups' => $product->modifierGroups,
        ]);
    }
}
