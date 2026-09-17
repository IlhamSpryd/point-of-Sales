<?php

namespace App\Models;

use App\Enums\OrderType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        'cash_received',
        'table_id', // PERUBAHAN: menggantikan table_number string, lihat migration
        'order_type',
    ];

    /**
     * Konversi tipe data otomatis.
     */
    protected function casts(): array
    {
        return [
            'order_date' => 'date',
            'order_type' => OrderType::class,
        ];
    }

    /**
     * Relasi (BelongsTo): Setiap Pesanan ditangani atau dibuat oleh seorang Pengguna spesifik (kasir).
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    /**
     * Relasi (HasMany): Pesanan utama menaungi banyak baris rincian barang yang dibeli secara spesifik.
     */
    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * Relasi (BelongsTo): Pesanan self-order ini berasal dari meja mana.
     * Bernilai null untuk transaksi dari Kasir (POS reguler tanpa konsep meja).
     */
    public function table(): BelongsTo
    {
        return $this->belongsTo(Table::class);
    }
}
