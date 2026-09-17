<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Model Category berguna untuk menyimpan klasifikasi tipe
 * pada katalog sistem produk jualan (contoh: Minuman, Makanan Ringan).
 */
class Category extends Model
{
    use SoftDeletes;

    /**
     * Komponen isian kolom database kategori.
     */
    protected $fillable = ['category_name'];

    /**
     * Relasi (HasMany): Sebuah kategori lazimnya memayungi banyak daftar produk.
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
