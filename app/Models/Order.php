<?php
declare(strict_types=1);

namespace App\Models;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentMethod;
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
     *
     * [OMEGA-NODE1] SENGAJA TIDAK memasukkan: order_status, payment_method,
     * snap_token, voided_by, void_reason, voided_at. Field-field ini adalah
     * transisi status/keuangan yang HANYA boleh diubah lewat forceFill()
     * eksplisit di TransactionService (titik tunggal kebenaran), BUKAN lewat
     * mass-assignment biasa. JANGAN tambahkan ke daftar ini tanpa audit ulang.
     */
    protected $fillable = [
        'user_id',
        'shift_id',
        'order_code',
        'order_date',
        'subtotal_amount',
        'discount_amount',
        'tax_amount',
        'service_charge_amount',
        'order_change',
        'cash_received',
        'table_id',
        'order_type',
        'discount_id',
    ];

    /**
     * Konversi tipe data otomatis.
     */
    protected function casts(): array
    {
        return [
            'order_date' => 'date',
            'order_type' => OrderType::class,
            'order_status' => OrderStatus::class,
            'payment_method' => PaymentMethod::class,
            'voided_at' => 'datetime',
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

    /** Relasi (BelongsTo): Shift kasir yang aktif saat transaksi ini dibuat. */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    /** Relasi (BelongsTo): Master diskon yang diterapkan pada transaksi ini, jika ada. */
    public function discount(): BelongsTo
    {
        return $this->belongsTo(Discount::class);
    }

    /** Relasi (BelongsTo): Pengguna yang membatalkan (void) transaksi ini, jika ada. */
    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by')->withTrashed();
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
