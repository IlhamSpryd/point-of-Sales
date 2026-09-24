<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\MenuCacheService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class Ingredient extends Model
{
    use SoftDeletes;

    protected $guarded = ['id'];

    protected $casts = [
        'cost_per_unit' => 'decimal:4',
        'current_stock' => 'decimal:4',
        'reorder_level' => 'decimal:4',
    ];

    protected static function booted(): void
    {
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
            $stockCrossedToZero = $ingredient->wasChanged('current_stock')
                && (float) $ingredient->current_stock <= 0
                && (float) $ingredient->getOriginal('current_stock') > 0;

            $stockCrossedFromZero = $ingredient->wasChanged('current_stock')
                && (float) $ingredient->current_stock > 0
                && (float) $ingredient->getOriginal('current_stock') <= 0;

            if ($stockCrossedToZero || $stockCrossedFromZero) {
                DB::afterCommit(fn () => app(MenuCacheService::class)->flush());
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
