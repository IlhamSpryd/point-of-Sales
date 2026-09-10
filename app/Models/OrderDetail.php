<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Model OrderDetail mengatur spesifikasi parsial dalam keranjang transaksi,
 * mencakup produk apa saja yang dibeli, quantitas, serta subtotal per itemnya.
 */
class OrderDetail extends Model
{
    /**
     * Kumpulan atribut pembentuk rincian pesanan.
     */
    protected $fillable = ['order_id', 'product_id', 'qty', 'order_price', 'order_subtotal'];

    /**
     * Relasi (BelongsTo): Setiap Rincian selalu tertaut dengan Induk tagihannya (Pesanan).
     */
    public function order(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Relasi (BelongsTo): Setiap baris rincian bertindak untuk mengambil acuan satu barang komoditas.
     */
    public function product(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
