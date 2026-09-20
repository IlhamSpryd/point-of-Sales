<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Support\Facades\Cache;

/**
 * MenuCacheService memusatkan caching katalog menu (Kategori + Produk + Modifier).
 *
 * LATAR BELAKANG: query katalog yang identik/mirip sebelumnya dijalankan ULANG
 * dari NOL di beberapa tempat berbeda (Self-Order customer, Menu customer,
 * Kasir POS, Livewire Kasir) -- padahal data ini HANYA berubah saat
 * Admin/Inventory mengubah produk/kategori (jarang), sementara dibaca ULANG
 * ratusan/ribuan kali per hari. TTL panjang + invalidasi manual saat mutasi
 * adalah pilihan tepat untuk pola akses seperti ini.
 */
class MenuCacheService
{
    private const TTL_SECONDS = 3600; // 1 jam -- selalu di-flush manual saat data berubah

    private const KEY_CATALOG = 'pos:menu:catalog:v1';

    private const KEY_CATEGORIES = 'pos:menu:categories:v1';

    private const KEY_ACTIVE_PRODUCTS = 'pos:menu:active-products:v1';

    private const KEY_MENU_DISPLAY = 'pos:menu:display:v1';

    /**
     * Katalog Self-Order: Kategori -> Produk TERSEDIA (aktif & stok>0) -> Varian.
     * Dipakai oleh Livewire Kasir\CreateOrder::categories().
     */
    public function getCatalog()
    {
        return Category::with([
            'products' => fn ($q) => $q->availableForOrder()->with('modifierGroups.modifiers'),
        ])->get();
    }

    /** Semua kategori tanpa filter -- dipakai untuk dropdown/filter UI Kasir POS. */
    public function getAllCategories()
    {
        return Category::all();
    }

    /** Produk aktif + kategori (flat) -- dipakai oleh layar Kasir POS (TransactionController). */
    public function getActiveProductsWithCategory()
    {
        return Product::where('is_active', true)->with('category')->get();
    }

    /**
     * Data tampilan Menu customer (Customer\MenuController): SEMUA kategori +
     * produk aktif (termasuk stok habis, untuk badge "Habis") -- berbeda dari
     * getCatalog() yang menyembunyikan produk stok habis di alur self-order.
     */
    public function getMenuDisplayData(): array
    {
        return [
            'categories' => Category::all(),
            'products' => Product::where('is_active', true)
                ->with(['category', 'modifierGroups.modifiers'])
                ->get(),
        ];
    }

    /**
     * WAJIB dipanggil setiap kali ada mutasi (create/update/delete) pada Produk
     * ATAU Kategori, agar kasir/pelanggan tidak melihat data katalog basi.
     */
    public function flush(): void
    {
        Cache::forget(self::KEY_CATALOG);
        Cache::forget(self::KEY_CATEGORIES);
        Cache::forget(self::KEY_ACTIVE_PRODUCTS);
        Cache::forget(self::KEY_MENU_DISPLAY);
    }
}
