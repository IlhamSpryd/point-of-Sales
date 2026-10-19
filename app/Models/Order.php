<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\OrderTypeCast;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatusEnum;
use App\Models\Concerns\AssignsStore;
use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToStore;
use App\Models\Concerns\ScopedToTenant;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Model Order menyimpan segala informasi garis besar sebuah transaksi (struk total),
 * mencakup uang kumulatif, kembalian, hingga status dari gerbang pembayaran.
 *
 * @property int $id
 * @property int|null $tenant_id
 * @property int|null $store_id
 * @property string $order_code
 * @property OrderStatus $order_status
 * @property PaymentMethod|null $payment_method
 * @property Collection<int, OrderItem> $orderItems
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Order extends Model
{
    use AssignsStore, AssignsTenant;
    use ScopedToStore;
    use ScopedToTenant;

    /**
     * Data isian order yang diizinkan untuk disisipkan ke dalam basis data secara bersamaan.
     *
     * SENGAJA TIDAK memasukkan: order_status, payment_method,
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
        'idempotency_key',
        'order_amount',
        'subtotal_amount',
        'discount_amount',
        'tax_amount',
        'service_charge_amount',
        'order_change',
        'cash_received',
        'table_id',
        'order_type',
        'discount_id',
        'origin_channel',
        'source_reference',
        'created_by_device_id',
    ];

    /**
     * Konversi tipe data otomatis.
     */
    protected function casts(): array
    {
        return [
            'order_date' => 'date',
            'order_type' => OrderTypeCast::class,
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

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'created_by_device_id');
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

    public function paymentAttempts(): HasMany
    {
        return $this->hasMany(PaymentAttempt::class);
    }

    public function orderReturns(): HasMany
    {
        return $this->hasMany(OrderReturn::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(PaymentRefund::class);
    }

    public function paymentRefunds(): HasMany
    {
        return $this->hasMany(PaymentRefund::class);
    }

    public function discountRedemptions(): HasMany
    {
        return $this->hasMany(DiscountRedemption::class);
    }

    public function parkedCart(): HasOne
    {
        return $this->hasOne(ParkedCart::class, 'converted_order_id');
    }

    public function printLogs(): HasMany
    {
        return $this->hasMany(ReceiptPrintLog::class);
    }

    public function getSettledPaidAmount(): int
    {
        return (int) $this->payments()
            ->whereIn('status', [PaymentStatusEnum::Captured, 'settled'])
            ->sum('amount');
    }

    public function getRemainingBalance(): int
    {
        return max(0, (int) $this->order_amount - $this->getSettledPaidAmount());
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
