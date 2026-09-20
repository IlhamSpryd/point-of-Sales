<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\MenuCacheService;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

/**
 * MenuController: Melayani sisi pelanggan (customer self-order).
 * Berbeda dengan ProductController (admin), controller ini HANYA read-only
 * dan tidak pernah menyentuh proses create/update/delete produk.
 */
class MenuController extends Controller
{
    public function __construct(protected MenuCacheService $menuCache) {}

    /**
     * Menampilkan katalog menu untuk pelanggan.
     * OPTIMASI: query katalog (kategori + produk aktif + modifier) diambil dari
     * MenuCacheService, karena endpoint ini adalah endpoint PUBLIK dengan
     * traffic TERTINGGI di seluruh sistem -- dipanggil setiap pelanggan scan QR meja.
     */
    public function index(): View
    {
        $data = $this->menuCache->getMenuDisplayData();

        return view('customer.menu.index', [
            'categories' => $data['categories'],
            'products' => $data['products'],
        ]);
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
