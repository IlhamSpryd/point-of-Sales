<?php

namespace App\Models;

use App\Enums\PreparationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model OrderItem mengatur spesifikasi parsial dalam keranjang transaksi,
 * mencakup produk apa saja yang dibeli, quantitas, serta subtotal per itemnya.
 */
class OrderItem extends Model
{
    /**
     * Kumpulan atribut pembentuk rincian pesanan.
     */
    protected $fillable = ['order_id', 'product_id', 'qty', 'order_price', 'order_subtotal', 'options', 'notes', 'preparation_status'];

    /**
     * BARU: "options" disimpan sebagai JSON di database, tapi otomatis
     * di-decode jadi array PHP setiap kali diakses lewat Eloquent.
     */
    protected function casts(): array
    {
        return [
            'options' => 'array',
            'preparation_status' => PreparationStatus::class,
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
     * Relasi (BelongsTo): Setiap baris rincian bertindak untuk mengambil acuan satu barang komoditas.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
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
