<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToTenant;
use App\Services\Context\TenantContext;
use App\Services\MenuCacheService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class Ingredient extends Model
{
    use AssignsTenant;
    use ScopedToTenant;
    use SoftDeletes;

    protected $guarded = ['id'];

    protected $casts = [
        'cost_per_unit' => 'decimal:4',
        'current_stock' => 'decimal:4',
        'reorder_level' => 'decimal:4',
    ];

    protected static function booted(): void
    {
        static::created(function ($model) {
            $stores = Store::where('tenant_id', $model->tenant_id)->orderBy('id')->get();
            $defaultStore = $stores->first();
            foreach ($stores as $store) {
                $qty = ($defaultStore && $store->id === $defaultStore->id) ? $model->current_stock : 0;
                DB::table('ingredient_stock_balances')->insertOrIgnore([
                    'tenant_id' => $model->tenant_id,
                    'store_id' => $store->id,
                    'ingredient_id' => $model->id,
                    'quantity' => $qty,
                    'baseline_quantity' => $qty,
                    'baseline_movement_id' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });

        // PATCH FOR F-07: BOM Cache Invalidation.
        //
        // Menutup celah yang didokumentasikan di Product::scopeAvailableForOrder():
        // produk ber-BOM tidak memiliki tracking `products.stock`, sehingga
        // hook Product::saved() TIDAK BISA mendeteksi produk ber-BOM yang
        // kehabisan bahan baku. Solusi: pantau ingredients.current_stock di
        // sini -- saat ambang nol dilewati (habis atau kembali tersedia),
        // flush menu cache agar pelanggan tidak bisa memesan produk yang
        // bahan bakunya sudah habis.
        //
        // Pola IDENTIK dengan Product::booted()::saved(): hanya bereaksi
        // pada perubahan yang bermakna (melewati nol), bukan setiap mutasi
        // stok, untuk menghindari flush berlebihan. DB::afterCommit()
        // digunakan karena decrement() terjadi di dalam DB::transaction()
        // milik TransactionService::createTransaction().
        static::saved(function (Ingredient $ingredient) {
            if ($ingredient->wasChanged('current_stock')) {
                $context = app(TenantContext::class);
                $storeId = $context->getStoreId() ?? Store::where('tenant_id', $ingredient->tenant_id)->orderBy('id')->value('id');

                if ($storeId) {
                    $delta = $ingredient->current_stock - $ingredient->getOriginal('current_stock');
                    DB::table('ingredient_stock_balances')
                        ->where('tenant_id', $ingredient->tenant_id)
                        ->where('store_id', $storeId)
                        ->where('ingredient_id', $ingredient->id)
                        ->update([
                            'quantity' => DB::raw("quantity + ($delta)"),
                            'updated_at' => now(),
                        ]);
                }
            }

            $stockCrossedToZero = $ingredient->wasChanged('current_stock')
                && (float) $ingredient->current_stock <= 0
                && (float) $ingredient->getOriginal('current_stock') > 0;

            $stockCrossedFromZero = $ingredient->wasChanged('current_stock')
                && (float) $ingredient->current_stock > 0
                && (float) $ingredient->getOriginal('current_stock') <= 0;

            if ($stockCrossedToZero || $stockCrossedFromZero) {
                DB::afterCommit(fn () => app(MenuCacheService::class)->flush($ingredient->tenant_id));
            }
        });
    }

    /**
     * [OMEGA-NODE1] FIX PRASYARAT BOM: Hapus 'unit_of_measurement' dari pivot.
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_ingredients')
            ->withPivot('quantity_required');
    }

    public static function lockAndGetIngredient(int $id): ?self
    {
        return self::where('id', $id)->lockForUpdate()->first();
    }
}
