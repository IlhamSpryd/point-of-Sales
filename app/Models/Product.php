<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\MenuCacheService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

/**
 * Model Product untuk memetakan rekaman setiap komoditas atau
 * barang dagangan yang diperjualbelikan pada antarmuka sistem.
 */
class Product extends Model
{
    use SoftDeletes;

    /**
     * Atribut yang diizinkan untuk diisi massal oleh aplikasi.
     */
    protected $fillable = [
        'category_id', 'product_name', 'product_photo', 'product_price',
        'product_description', 'stock', 'is_active', 'product_code',
    ];

    protected static function booted()
    {
        static::creating(function ($model) {
            if (empty($model->product_code)) {
                $latest = static::latest('id')->first();
                $nextId = $latest ? $latest->id + 1 : 1;
                $model->product_code = 'PRD-'.str_pad((string) $nextId, 4, '0', STR_PAD_LEFT);
            }
        });

        // [OMEGA-NODE3] Invalidasi cache OTOMATIS saat status ketersediaan
        // produk berubah. Menutup celah: TransactionService::createTransaction()
        // memotong `stock` lewat Product::decrement() (untuk produk TANPA
        // resep BOM) -- jalur ini TIDAK PERNAH melalui ProductService,
        // satu-satunya tempat MenuCacheService::flush() dipanggil manual
        // sebelumnya. Tanpa hook ini, produk yang baru saja habis terjual
        // tetap tampil "tersedia" di menu Self-Order/Kasir hingga TTL
        // cache (1 jam) habis -- pelanggan bisa menambah produk itu ke
        // keranjang lalu baru gagal saat checkout. Hook yang sama juga
        // menangkap arah sebaliknya: TransactionService::restoreStockForOrder()
        // memakai Product::increment('stock', ...) saat order dibatalkan/
        // kedaluwarsa -- produk yang tadinya habis harus kembali tampil.
        //
        // Model::increment()/decrement() MEMANGGIL save() secara internal
        // ketika model sudah exists, sehingga event 'saved' di bawah ini
        // pasti terpicu untuk KEDUA operasi tersebut.
        //
        // Sengaja HANYA bereaksi saat stok melewati ambang 0 (bukan pada
        // SETIAP perubahan angka stok) atau saat is_active berubah --
        // bukan pada setiap save() -- agar tidak menggandakan flush() yang
        // sudah dipanggil eksplisit oleh ProductService pada perubahan
        // nama/harga/foto, dan agar penjualan normal (stok 50 -> 49) tidak
        // memicu invalidasi cache yang tidak perlu.
        static::saved(function (Product $product) {
            $stockCrossedToZero = $product->wasChanged('stock')
                && $product->stock <= 0
                && (int) $product->getOriginal('stock') > 0;

            $stockCrossedFromZero = $product->wasChanged('stock')
                && $product->stock > 0
                && (int) $product->getOriginal('stock') <= 0;

            $visibilityChanged = $product->wasChanged('is_active');

            if ($stockCrossedToZero || $stockCrossedFromZero || $visibilityChanged) {
                // WAJIB DB::afterCommit(), BUKAN flush() langsung: hook ini
                // bisa terpicu di tengah DB::transaction() milik
                // TransactionService::createTransaction() SAAT baris
                // products masih dikunci (lockForUpdate()). Melakukan I/O
                // tambahan (DELETE ke tabel `cache`, yang notabene berbagi
                // koneksi database yang sama karena CACHE_STORE=database)
                // SELAGI lock baris masih dipegang akan memperpanjang
                // critical section dan menambah kontensi di bawah beban
                // banyak kasir bersamaan -- tepat kebalikan dari tujuan
                // optimasi ini. DB::afterCommit() menunda flush() sampai
                // TEPAT setelah transaksi benar-benar commit (dan berjalan
                // seketika jika model ini disimpan di luar transaksi sama
                // sekali, misal lewat ProductController biasa).
                DB::afterCommit(fn () => app(MenuCacheService::class)->flush());
            }
        });
    }

    /**
     * Konversi boolean di ranah aplikasi meskipun tersimpan dalam format TinyInt database.
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * Relasi (BelongsTo): Setiap Entitas Produk harus masuk ke dalam suatu entitas kategori tertentu.
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * Relasi (HasMany): Produk terkait sering dilampirkan dalam ragam deretan bon rincian pesanan.
     */
    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * Relasi (BelongsToMany): Produk ini memakai grup varian apa saja.
     * Contoh: Produk "Kopi Susu Gula Aren" memakai grup "Pilihan Suhu" dan "Tingkat Gula".
     */
    public function modifierGroups(): BelongsToMany
    {
        return $this->belongsToMany(ModifierGroup::class, 'modifier_group_product');
    }

    /**
     * KUNCI INTEGRASI: menu self-order & kasir HANYA boleh menampilkan produk
     * yang benar-benar bisa dijual -- aktif, tidak di-soft-delete, stok ada.
     *
     * [OMEGA-NODE1] CATATAN AUDIT: untuk produk ber-BOM, `stock` di sini TIDAK LAGI dipotong
     * oleh TransactionService::createTransaction().
     *
     * [OMEGA-NODE3] KETERBATASAN DIKETAHUI (di luar cakupan audit ini):
     * untuk produk ber-BOM, ketersediaan sesungguhnya ditentukan oleh
     * `ingredients.current_stock`, BUKAN kolom `stock` di tabel ini --
     * kolom `stock` produk ber-BOM tidak lagi dimutakhirkan sama sekali
     * (lihat catatan Node 1 di atas).
     *
     * [F-07 RESOLVED] Cache invalidation untuk produk ber-BOM kini
     * ditangani oleh Ingredient::booted()::saved() -- saat current_stock
     * melewati ambang nol, menu cache di-flush otomatis via
     * DB::afterCommit(), pola identik dengan Product::booted()::saved().
     */
    public function scopeAvailableForOrder($query)
    {
        return $query->where('is_active', true)->where('stock', '>', 0);
    }

    /**
     * [OMEGA-NODE1] FIX PRASYARAT BOM: Hapus 'unit_of_measurement' karena tidak ada di tabel pivot.
     */
    public function ingredients(): BelongsToMany
    {
        return $this->belongsToMany(Ingredient::class, 'product_ingredients')
            ->withPivot('quantity_required');
    }
}
