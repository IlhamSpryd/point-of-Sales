<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Model Category berguna untuk menyimpan klasifikasi tipe
 * pada katalog sistem produk jualan (contoh: Minuman, Makanan Ringan).
 */
class Category extends Model
{
    /**
     * Komponen isian kolom database kategori.
     */
    protected $fillable = ['category_name'];

    /**
     * Relasi (HasMany): Sebuah kategori lazimnya memayungi banyak daftar produk.
     */
    public function products(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Product::class);
    }
}
