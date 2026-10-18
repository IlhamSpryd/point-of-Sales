<?php

namespace App\Models;

use App\Enums\PreparationStatus;
use App\Models\Concerns\AssignsStore;
use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToStore;
use App\Models\Concerns\ScopedToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Model OrderItem mengatur spesifikasi parsial dalam keranjang transaksi,
 * mencakup produk apa saja yang dibeli, quantitas, serta subtotal per itemnya.
 *
 * @property int $id
 * @property int $order_id
 * @property int $product_id
 * @property int $qty
 * @property int|float $order_price
 * @property int|float $order_subtotal
 * @property array<string, mixed>|null $options
 * @property string|null $notes
 * @property PreparationStatus $preparation_status
 * @property int|null $processed_by
 * @property int|null $tenant_id
 * @property int|null $store_id
 */
class OrderItem extends Model
{
    use AssignsStore, AssignsTenant;
    use ScopedToStore;
    use ScopedToTenant;

    /**
     * Kumpulan atribut pembentuk rincian pesanan.
     */
    protected $fillable = [
        'order_id',
        'product_id',
        'qty',
        'order_price',
        'order_subtotal',
        'options',
        'notes',
        'preparation_status',
        'processed_by',
        'tenant_id',
        'store_id',
        'item_discount_id',
        'item_discount_amount',
        'allocated_order_discount_amount',
        'net_line_total',
    ];

    /**
     * BARU: "options" disimpan sebagai JSON di database, tapi otomatis
     * di-decode jadi array PHP setiap kali diakses lewat Eloquent.
     */
    protected function casts(): array
    {
        return [
            'options' => 'array',
            'preparation_status' => PreparationStatus::class,
            'item_discount_amount' => 'decimal:2',
            'allocated_order_discount_amount' => 'decimal:2',
            'net_line_total' => 'decimal:2',
        ];
    }

    /**
     * Relasi (BelongsTo): Setiap Rincian selalu tertaut dengan Induk tagihannya (Pesanan).
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Relasi (BelongsTo): Diskon spesifik per baris produk jika ada.
     */
    public function itemDiscount(): BelongsTo
    {
        return $this->belongsTo(Discount::class, 'item_discount_id');
    }

    /**
     * Relasi (BelongsTo): Setiap baris rincian bertindak untuk mengambil acuan satu barang komoditas.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    /**
     * Relasi (BelongsToMany): Modifier/Add-ons yang dipilih untuk item pesanan ini.
     */
    public function modifiers(): BelongsToMany
    {
        return $this->belongsToMany(Modifier::class, 'order_item_modifiers')
            ->using(OrderItemModifier::class)
            ->withPivot('price_at_time', 'qty', 'tenant_id', 'store_id')
            ->withTimestamps();
    }

    /**
     * Relasi (BelongsTo): Pengguna (barista/dapur) yang memproses pesanan ini.
     */
    public function processedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    /**
     * Menghitung berapa menit berlalu sejak pesanan dibuat.
     */
    public function slaMinutesElapsed(): int
    {
        return (int) $this->created_at->diffInMinutes(now());
    }

    /**
     * Menentukan level SLA (ok, warning, critical) berdasarkan waktu tunggu.
     */
    public function slaLevel(): string
    {
        $elapsed = $this->slaMinutesElapsed();
        $warning = config('pos.kds_sla_warning_minutes', 8);
        $critical = config('pos.kds_sla_critical_minutes', 15);

        if ($elapsed >= $critical) {
            return 'critical';
        }

        if ($elapsed >= $warning) {
            return 'warning';
        }

        return 'ok';
    }
}
