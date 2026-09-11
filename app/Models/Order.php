<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Model Order menyimpan segala informasi garis besar sebuah transaksi (struk total),
 * mencakup uang kumulatif, kembalian, hingga status dari gerbang pembayaran.
 */
class Order extends Model
{
    /**
     * Data isian order yang diizinkan untuk disisipkan ke dalam basis data secara bersamaan.
     */
    protected $fillable = [
        'user_id',
        'order_code',
        'order_date',
        'subtotal_amount',
        'tax_amount',
        'order_amount',
        'order_change',
        'order_status',
        'payment_method',
        'snap_token',
        'cash_received',
    ];

    /**
     * Konversi tipe data otomatis.
     */
    protected function casts(): array
    {
        return [
            'order_date' => 'date',
        ];
    }

    /**
     * Relasi (BelongsTo): Setiap Pesanan ditangani atau dibuat oleh seorang Pengguna spesifik (kasir).
     */
    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Relasi (HasMany): Pesanan utama menaungi banyak baris rincian barang yang dibeli secara spesifik.
     */
    public function orderDetails(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(OrderDetail::class);
    }
}
