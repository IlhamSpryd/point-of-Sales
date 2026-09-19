<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

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
        'product_description', 'stock', 'is_active', 'product_code'
    ];

    protected static function booted() {
        static::creating(function ($model) {
            if (empty($model->product_code)) {
                $latest = static::latest('id')->first();
                $nextId = $latest ? $latest->id + 1 : 1;
                $model->product_code = 'PRD-' . str_pad($nextId, 4, '0', STR_PAD_LEFT);
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
     */
    public function scopeAvailableForOrder($query)
    {
        return $query->where('is_active', true)->where('stock', '>', 0);
    }
}
