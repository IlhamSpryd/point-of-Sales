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
    protected $fillable = ['category_name', 'category_code'];

    protected static function booted()
    {
        static::creating(function ($model) {
            if (empty($model->category_code)) {
                $latest = static::latest('id')->first();
                $nextId = $latest ? $latest->id + 1 : 1;
                $model->category_code = 'CAT-'.str_pad($nextId, 3, '0', STR_PAD_LEFT);
            }
        });
    }

    /**
     * Relasi (HasMany): Sebuah kategori lazimnya memayungi banyak daftar produk.
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
