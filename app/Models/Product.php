<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Model Product untuk memetakan rekaman setiap komoditas atau
 * barang dagangan yang diperjualbelikan pada antarmuka sistem.
 */
class Product extends Model
{
    /**
     * Atribut yang diizinkan untuk diisi massal oleh aplikasi.
     */
    protected $fillable = [
        'category_id', 'product_name', 'product_photo', 'product_price',
        'product_description', 'stock', 'is_active'
    ];

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
    public function category(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * Relasi (HasMany): Produk terkait sering dilampirkan dalam ragam deretan bon rincian pesanan.
     */
    public function orderDetails(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(OrderDetail::class);
    }
}
