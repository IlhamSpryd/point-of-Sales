<?php

declare(strict_types=1);

// [OMEGA-NODE3] Rancang ulang strategi cache katalog menu -- Node 3 Performance & Caching | 2026-09-22

namespace App\Services;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * MenuCacheService: lapisan cache-aside untuk katalog menu (Kasir & Self-Order).
 *
 * [OMEGA-NODE3] KEPUTUSAN ARSITEKTUR -- KENAPA TANPA Cache::tags():
 * CACHE_STORE default aplikasi ini adalah 'database' (lihat .env.example
 * dan config/cache.php: 'default' => env('CACHE_STORE', 'database')).
 * Driver 'database' TIDAK MENDUKUNG tagged cache -- hanya redis,
 * memcached, dynamodb, dan array yang mendukung Cache::tags(). Memanggil
 * Cache::tags() di atas driver 'database' akan melempar
 * BadMethodCallException saat runtime pertama kali dipanggil, bukan saat
 * migrasi/deploy -- kegagalan yang baru ketahuan di production.
 *
 * Strategi yang dipakai di sini adalah KEY EKSPLISIT + INVALIDASI
 * EKSPLISIT/EVENT-DRIVEN, yang kompatibel dengan SEMUA driver cache
 * Laravel termasuk 'database' yang sedang aktif sekarang. Lihat
 * rekomendasi migrasi ke Redis di laporan (bagian Config & Throughput)
 * untuk membuka opsi tag-based invalidation yang lebih granular di masa
 * depan tanpa perlu menulis ulang service ini.
 */
class MenuCacheService
{
    private const TTL_SECONDS = 3600;

    private const KEY_CATALOG = 'pos:menu:catalog:v2';

    private const KEY_CATEGORIES = 'pos:menu:categories:v2';

    private const KEY_ACTIVE_PRODUCTS = 'pos:menu:active-products:v2';

    private const KEY_MENU_DISPLAY = 'pos:menu:display:v2';

    /**
     * Nama lock atomik (memakai tabel `cache_locks`, driver 'database'
     * SUDAH mendukung atomic locks -- lihat pola identik yang sudah
     * dipakai TransactionController::store() untuk idempotency).
     */
    private const REBUILD_LOCK_KEY = 'pos:menu:rebuild-lock';

    private const REBUILD_LOCK_WAIT_SECONDS = 5;

    /**
     * [OMEGA-NODE3] FIX KRITIKAL DITEMUKAN SAAT AUDIT: versi SEBELUMNYA
     * method ini TIDAK PERNAH membungkus query dengan Cache::remember() --
     * padahal dipanggil sebagai #[Computed] property di
     * App\Livewire\Kasir\CreateOrder::categories(), yang komentarnya
     * BAHKAN mengklaim "MenuCacheService::getCatalog() pakai
     * Cache::remember (Laravel Cache, TTL 1 jam)". Klaim itu SALAH
     * sebelum perbaikan ini: query Category -> products(availableForOrder)
     * -> modifierGroups.modifiers dijalankan ULANG dari nol pada SETIAP
     * re-render Livewire di layar Kasir POS -- layar dengan frekuensi
     * interaksi TERTINGGI di seluruh sistem (setiap klik produk, setiap
     * toggle modifier, setiap perubahan qty memicu render ulang). Ini
     * adalah temuan N+1/beban-DB paling signifikan pada audit Node 3.
     */
    public function getCatalog()
    {
        return Cache::remember(self::KEY_CATALOG, self::TTL_SECONDS, function () {
            return Category::with([
                'products' => fn ($q) => $q->availableForOrder()->with('modifierGroups.modifiers'),
            ])->get();
        });
    }

    public function getAllCategories()
    {
        return Cache::remember(self::KEY_CATEGORIES, self::TTL_SECONDS, function () {
            return Category::all();
        });
    }

    public function getActiveProductsWithCategory()
    {
        return Cache::remember(self::KEY_ACTIVE_PRODUCTS, self::TTL_SECONDS, function () {
            return Product::where('is_active', true)->with('category')->get();
        });
    }

    /**
     * [OMEGA-NODE3] Endpoint dengan traffic TERTINGGI di seluruh sistem:
     * dipanggil setiap kali pelanggan scan QR meja (rute PUBLIK, tanpa
     * auth, hanya throttle:60,1 per-IP -- lihat routes/customer.php).
     * Cache-miss di endpoint publik bertraffic tinggi rawan "thundering
     * herd": puluhan pelanggan scan QR bersamaan tepat saat TTL habis
     * bisa memukul database dengan query berat yang SAMA secara paralel,
     * masing-masing menanggung join Category+Product+ModifierGroup+Modifier.
     *
     * Cache::lock() memastikan HANYA SATU proses yang benar-benar
     * menjalankan query rebuild; proses lain menunggu maksimal
     * REBUILD_LOCK_WAIT_SECONDS lalu membaca hasil yang baru ditulis oleh
     * pemenang lock -- pola yang SAMA PERSIS dengan
     * TransactionController::store() (Cache::lock('order_idempotency_...'))
     * yang sudah teruji berjalan baik di proyek ini.
     */
    public function getMenuDisplayData(): array
    {
        $cached = Cache::get(self::KEY_MENU_DISPLAY);

        if ($cached !== null) {
            return $cached;
        }

        try {
            return Cache::lock(self::REBUILD_LOCK_KEY, 10)
                ->block(self::REBUILD_LOCK_WAIT_SECONDS, function () {
                    return Cache::remember(
                        self::KEY_MENU_DISPLAY,
                        self::TTL_SECONDS,
                        fn () => $this->buildMenuDisplayData()
                    );
                });
        } catch (LockTimeoutException) {
            // Lock tidak didapat dalam batas waktu -- daripada membuat
            // pelanggan menunggu tanpa kepastian, jalankan query langsung
            // TANPA menulis ulang cache (proses pemenang lock yang akan
            // menuliskannya). Latensi request INI sedikit lebih tinggi,
            // tapi request tidak diblokir dan beban DB tidak digandakan.
            Log::warning('[OMEGA-NODE3] Menu display cache rebuild lock timeout -- fallback ke query langsung.');

            return $this->buildMenuDisplayData();
        }
    }

    /**
     * Query mentah katalog Self-Order. Dipisah dari getMenuDisplayData()
     * agar bisa dipanggil ulang baik oleh jalur cache normal (Cache::remember)
     * maupun jalur fallback (lock timeout) tanpa duplikasi logika.
     */
    private function buildMenuDisplayData(): array
    {
        return [
            'categories' => Category::all(),
            'products' => Product::where('is_active', true)
                ->with(['category', 'modifierGroups.modifiers'])
                ->get(),
        ];
    }

    /**
     * Invalidasi PENUH seluruh cache katalog menu.
     *
     * Dipanggil eksplisit oleh CategoryService & ProductService pada
     * setiap store/update/delete (SUDAH ADA sebelumnya, TIDAK diubah di
     * sini) -- DAN sekarang JUGA dipanggil otomatis oleh
     * App\Models\Product::booted()::saved() saat status ketersediaan
     * produk berubah lewat jalur LAIN yang sebelumnya tidak pernah
     * menyentuh cache ini sama sekali. Lihat SYNC ALERT di laporan untuk
     * detail celah yang ditutup dan alasan pemanggilannya lewat
     * DB::afterCommit() (bukan langsung) di dalam model event tersebut.
     */
    public function flush(): void
    {
        Cache::forget(self::KEY_CATALOG);
        Cache::forget(self::KEY_CATEGORIES);
        Cache::forget(self::KEY_ACTIVE_PRODUCTS);
        Cache::forget(self::KEY_MENU_DISPLAY);
    }
}
