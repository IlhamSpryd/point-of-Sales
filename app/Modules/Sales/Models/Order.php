<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Casts\OrderTypeCast;
use App\Core\Traits\AssignsStore;
use App\Core\Traits\AssignsTenant;
use App\Core\Traits\ScopedToStore;
use App\Core\Traits\ScopedToTenant;
use App\Enums\OrderFulfillmentStatus;
use App\Enums\OrderLifecycleStatus;
use App\Enums\OrderPaymentStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatusEnum;
use App\Modules\Customers\Models\Customer;
use App\Modules\Organization\Models\Device;
use App\Modules\Organization\Models\OperationalUnit;
use App\Modules\Organization\Models\Store;
use App\Modules\Organization\Models\User;
use App\Modules\Pricing\Models\Discount;
use App\Modules\Pricing\Models\DiscountRedemption;
use App\Modules\Sales\Contracts\PosOrderReaderInterface;
use App\Modules\Sales\Services\TransactionService;
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
 * @property numeric-string $dpp_amount
 * @property numeric-string $rounding_adjustment
 * @property int $user_id
 * @property int|null $shift_id
 * @property int|null $discount_id
 * @property int|null $customer_id
 * @property int|null $sales_order_id
 * @property int|null $table_id
 * @property int|null $created_by_device_id
 * @property int|null $device_id
 * @property int $subtotal_amount
 * @property int $discount_amount
 * @property int $tax_amount
 * @property int $service_charge_amount
 * @property int $order_amount
 * @property int $order_change
 * @property int|null $cash_received
 * @property int $print_count
 * @property int|null $voided_by
 */
class Order extends Model implements PosOrderReaderInterface
{
    public function getId(): int
    {
        return (int) $this->id;
    }

    public function getTenantId(): int
    {
        return (int) $this->tenant_id;
    }

    public function getStoreId(): ?int
    {
        return $this->store_id !== null ? (int) $this->store_id : null;
    }

    public function getOrderNumber(): string
    {
        return (string) ($this->order_number ?? $this->order_code);
    }

    public function getTotalAmount(): float|string
    {
        return (string) ($this->total_amount ?? $this->order_amount ?? '0.00');
    }

    public function getTaxAmount(): float|string
    {
        return (string) ($this->tax_amount ?? '0.00');
    }

    public function getDiscountAmount(): float|string
    {
        return (string) ($this->discount_amount ?? '0.00');
    }

    public function getSubtotal(): float|string
    {
        return (string) ($this->subtotal ?? $this->subtotal_amount ?? '0.00');
    }

    public function getServiceChargeAmount(): float|string
    {
        return (string) ($this->service_charge_amount ?? '0.00');
    }

    public function getRoundingAdjustment(): float|string
    {
        return (string) ($this->rounding_adjustment ?? '0.00');
    }

    public function getCreatedAt(): mixed
    {
        return $this->created_at;
    }

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
        'customer_id',
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
        'table_number',
        'order_type',
        'discount_id',
        'origin_channel',
        'source_reference',
        'created_by_device_id',
        'sales_order_id',
        'is_offline_sync',
        'offline_synced_at',
        'offline_client_uuid',
        'currency_code',
        'exchange_rate',
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
            'order_lifecycle_status' => OrderLifecycleStatus::class,
            'payment_status' => OrderPaymentStatus::class,
            'fulfillment_status' => OrderFulfillmentStatus::class,
            'payment_method' => PaymentMethod::class,
            'voided_at' => 'datetime',
            'is_offline_sync' => 'boolean',
            'offline_synced_at' => 'datetime',
            'exchange_rate' => 'string',
        ];
    }

    /**
     * Relasi (BelongsTo): Setiap Pesanan ditangani atau dibuat oleh seorang Pengguna spesifik (kasir).
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Device, $this>
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'created_by_device_id');
    }

    /**
     * @return BelongsTo<OperationalUnit, $this>
     */
    public function operationalUnit(): BelongsTo
    {
        return $this->belongsTo(OperationalUnit::class);
    }

    /**
     * Transition the order lifecycle state safely.
     */
    public function transitionOrderLifecycle(OrderLifecycleStatus $status): self
    {
        $this->forceFill(['order_lifecycle_status' => $status])->save();

        return $this;
    }

    /**
     * Transition the payment state safely.
     */
    public function transitionPayment(OrderPaymentStatus $status): self
    {
        $this->forceFill(['payment_status' => $status])->save();

        return $this;
    }

    /**
     * Transition the fulfillment state safely.
     */
    public function transitionFulfillment(OrderFulfillmentStatus $status): self
    {
        $this->forceFill(['fulfillment_status' => $status])->save();

        return $this;
    }

    /**
     * Relasi (HasMany): Pesanan utama menaungi banyak baris rincian barang yang dibeli secara spesifik.
     *
     * @return HasMany<OrderItem, $this>
     */
    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * Relasi (BelongsTo): Pesanan self-order ini berasal dari meja mana.
     * Bernilai null untuk transaksi dari Kasir (POS reguler tanpa konsep meja).
     *
     * @return BelongsTo<Table, $this>
     */
    public function table(): BelongsTo
    {
        return $this->belongsTo(Table::class);
    }

    /** Relasi (BelongsTo): Shift kasir yang aktif saat transaksi ini dibuat.
     * @return BelongsTo<Shift, $this> */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    /** Relasi (BelongsTo): Master diskon yang diterapkan pada transaksi ini, jika ada.
     * @return BelongsTo<Discount, $this> */
    public function discount(): BelongsTo
    {
        return $this->belongsTo(Discount::class);
    }

    /** Relasi (BelongsTo): Pengguna yang membatalkan (void) transaksi ini, jika ada.
     * @return BelongsTo<User, $this> */
    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by')->withTrashed();
    }

    /**
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * @return HasMany<PaymentAttempt, $this>
     */
    public function paymentAttempts(): HasMany
    {
        return $this->hasMany(PaymentAttempt::class);
    }

    /**
     * @return HasMany<OrderReturn, $this>
     */
    public function orderReturns(): HasMany
    {
        return $this->hasMany(OrderReturn::class);
    }

    /**
     * @return HasMany<PaymentRefund, $this>
     */
    public function refunds(): HasMany
    {
        return $this->hasMany(PaymentRefund::class);
    }

    /**
     * @return HasMany<PaymentRefund, $this>
     */
    public function paymentRefunds(): HasMany
    {
        return $this->hasMany(PaymentRefund::class);
    }

    /**
     * @return HasMany<DiscountRedemption, $this>
     */
    public function discountRedemptions(): HasMany
    {
        return $this->hasMany(DiscountRedemption::class);
    }

    /**
     * @return HasOne<ParkedCart, $this>
     */
    public function parkedCart(): HasOne
    {
        return $this->hasOne(ParkedCart::class, 'converted_order_id');
    }

    /**
     * @return HasMany<ReceiptPrintLog, $this>
     */
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

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * @return BelongsTo<SalesOrder, $this>
     */
    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_id');
    }
}
