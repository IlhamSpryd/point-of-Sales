<?php

declare(strict_types=1);

namespace App\Modules\Products\Models;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToTenant;
use App\Models\Ingredient;
use App\Models\ModifierGroup;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductBundleItem;
use App\Models\ProductCostHistory;
use App\Models\ProductIngredient;
use App\Models\ProductMedia;
use App\Models\ProductUnitConversion;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\StoreProductAssortment;
use App\Models\TaxClass;
use App\Models\UnitOfMeasure;
use App\Services\Context\TenantContext;
use App\Services\MenuCacheService;
use App\Services\ProductService;
use App\Services\TransactionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

/**
 * Model Product untuk memetakan rekaman setiap komoditas atau
 * barang dagangan yang diperjualbelikan pada antarmuka sistem.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $product_name
 * @property string|null $product_code
 * @property string|null $sku
 * @property string|null $barcode
 * @property int|null $category_id
 * @property int|null $brand_id
 * @property int|null $base_uom_id
 * @property string $product_type
 * @property int|float $product_price
 * @property string $costing_method
 * @property float|null $standard_cost
 * @property int|null $tax_class_id
 * @property bool $is_tax_inclusive
 * @property bool $track_inventory
 * @property int $stock
 * @property string $status
 * @property bool $is_active
 * @property-read Category|null $category
 * @property-read Brand|null $brand
 * @property-read UnitOfMeasure|null $baseUom
 * @property-read TaxClass|null $taxClass
 * @property-read Collection<int, ProductVariant> $variants
 * @property-read Collection<int, Ingredient> $ingredients
 */
class Product extends Model
{
    use AssignsTenant;
    use HasFactory;
    use ScopedToTenant;
    use SoftDeletes;

    protected $attributes = [
        'status' => 'active',
        'is_active' => true,
        'has_variants' => false,
        'track_inventory' => true,
    ];

    /**
     * Atribut yang diizinkan untuk diisi massal oleh aplikasi.
     */
    protected $fillable = [
        'category_id',
        'brand_id',
        'base_uom_id',
        'product_name',
        'name',
        'product_code',
        'barcode',
        'product_type',
        'status',
        'has_variants',
        'track_inventory',
        'product_photo',
        'product_price',
        'price',
        'costing_method',
        'standard_cost',
        'tax_class_id',
        'is_tax_inclusive',
        'product_description',
        'stock',
        'is_active',
    ];

    public function setNameAttribute($value): void
    {
        $this->attributes['product_name'] = $value;
    }

    public function getNameAttribute(): ?string
    {
        return $this->attributes['product_name'] ?? null;
    }

    public function setPriceAttribute($value): void
    {
        $this->attributes['product_price'] = $value;
    }

    public function getPriceAttribute(): mixed
    {
        return $this->attributes['product_price'] ?? null;
    }

    protected static function booted()
    {
        static::created(function ($model) {
            $stores = Store::where('tenant_id', $model->tenant_id)->orderBy('id')->get();
            $defaultStore = $stores->first();
            foreach ($stores as $store) {
                $qty = ($defaultStore && $store->id === $defaultStore->id) ? $model->stock : 0;
                DB::table('product_stock_balances')->insertOrIgnore([
                    'tenant_id' => $model->tenant_id,
                    'store_id' => $store->id,
                    'product_id' => $model->id,
                    'quantity' => $qty,
                    'baseline_quantity' => $qty,
                    'baseline_movement_id' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });

        static::creating(function ($model) {
            if (empty($model->product_code)) {
                $latest = static::withoutTenantScope()->latest('id')->first();
                $nextId = $latest ? $latest->id + 1 : 1;
                $model->product_code = 'PRD-'.$model->tenant_id.'-'.str_pad((string) $nextId, 4, '0', STR_PAD_LEFT);
            }
        });

        // Invalidasi cache OTOMATIS saat status ketersediaan
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
            if ($product->wasChanged('stock')) {
                $context = app(TenantContext::class);
                $storeId = $context->getStoreId() ?? Store::where('tenant_id', $product->tenant_id)->orderBy('id')->value('id');

                if ($storeId) {
                    $delta = $product->stock - $product->getOriginal('stock');
                    DB::table('product_stock_balances')
                        ->where('tenant_id', $product->tenant_id)
                        ->where('store_id', $storeId)
                        ->where('product_id', $product->id)
                        ->update([
                            'quantity' => DB::raw("quantity + ($delta)"),
                            'updated_at' => now(),
                        ]);
                }
            }

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
                DB::afterCommit(fn () => app(MenuCacheService::class)->flush($product->tenant_id));
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
            'has_variants' => 'boolean',
            'track_inventory' => 'boolean',
            'standard_cost' => 'decimal:2',
        ];
    }

    /**
     * Relasi (BelongsTo): Setiap Entitas Produk harus masuk ke dalam suatu entitas kategori tertentu.
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function baseUom(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasure::class, 'base_uom_id');
    }

    public function unitConversions(): HasMany
    {
        return $this->hasMany(ProductUnitConversion::class);
    }

    public function taxClass(): BelongsTo
    {
        return $this->belongsTo(TaxClass::class, 'tax_class_id');
    }

    public function costHistories(): HasMany
    {
        return $this->hasMany(ProductCostHistory::class)->orderBy('effective_at', 'desc');
    }

    public function media(): HasMany
    {
        return $this->hasMany(ProductMedia::class)->orderBy('is_primary', 'desc')->orderBy('sort_order', 'asc');
    }

    /**
     * @return HasMany<StoreProductAssortment, $this>
     */
    public function storeAssortments(): HasMany
    {
        return $this->hasMany(StoreProductAssortment::class);
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
     * CATATAN AUDIT: untuk produk ber-BOM, `stock` di sini TIDAK LAGI dipotong
     * oleh TransactionService::createTransaction().
     *
     * KETERBATASAN DIKETAHUI (di luar cakupan audit ini):
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
        return $query->where('is_active', true)
            ->where('status', 'active')
            ->where(function ($q) {
                $q->where('track_inventory', false)
                    ->orWhereIn('product_type', ['service', 'non_inventory', 'bundle'])
                    ->orWhere('stock', '>', 0);
            });
    }

    public function scopeSellable(Builder $query): Builder
    {
        return $query->where('is_active', true)->where('status', 'active');
    }

    public function isSellable(): bool
    {
        return $this->is_active && $this->status === 'active';
    }

    public function isPhysical(): bool
    {
        return $this->product_type === 'standard';
    }

    public function isService(): bool
    {
        return $this->product_type === 'service';
    }

    public function isNonInventory(): bool
    {
        return $this->product_type === 'non_inventory';
    }

    public function isBundle(): bool
    {
        return $this->product_type === 'bundle';
    }

    /**
     * Relasi ke varian-varian spesifik (ADR-01).
     */
    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class, 'product_id')->orderBy('sort_order', 'asc');
    }

    /**
     * Relasi ke komponen penyusun bundel/kit jika produk bertipe bundle (ADR-04).
     */
    public function bundleItems(): HasMany
    {
        return $this->hasMany(ProductBundleItem::class, 'bundle_product_id')->orderBy('sort_order', 'asc');
    }

    /**
     * Relasi ke bundel-bundel di mana produk ini menjadi salah satu komponennya.
     */
    public function bundleComponentOf(): HasMany
    {
        return $this->hasMany(ProductBundleItem::class, 'component_product_id');
    }

    /**
     * FIX PRASYARAT BOM: Hapus 'unit_of_measurement' karena tidak ada di tabel pivot.
     */
    public function ingredients(): BelongsToMany
    {
        return $this->belongsToMany(Ingredient::class, 'product_ingredients')
            ->using(ProductIngredient::class)
            ->withPivot('quantity_required');
    }
}
