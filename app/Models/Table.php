<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Model Table merepresentasikan satu meja fisik di coffeeshop.
 * Setiap meja punya QR Code unik (dibuat dari secure_token) yang ditempel
 * di atas meja untuk memulai sesi pemesanan self-order.
 */
class Table extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'tables'; // eksplisit, agar tidak ambigu dengan nama class 'Table'

    protected $fillable = [
        'table_code',
        'table_name',
        'capacity',
        'area',
        'is_active',
        'operational_status',
        'secure_token',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * Setiap kali record Table baru dibuat, secure_token otomatis di-generate
     * sebagai UUID acak — Admin TIDAK PERNAH mengisi kolom ini secara manual,
     * mencegah Admin sengaja/tidak sengaja memilih token yang mudah ditebak.
     */
    protected static function booted(): void
    {
        static::creating(function (Table $table) {
            // Auto-generate Business ID
            if (empty($table->table_code)) {
                $latest = static::latest('id')->first();
                $nextId = $latest ? $latest->id + 1 : 1;
                $table->table_code = 'YVL-TBL-'.str_pad($nextId, 3, '0', STR_PAD_LEFT);
            }

            // KUNCI PERMANEN: Auto-generate Secure Token secara deterministik
            // Kita menggunakan md5 dari nama meja agar token tidak berubah
            // jika meja dihapus dan dibuat ulang, sehingga QR code fisik tidak perlu dicetak ulang.
            if (empty($table->secure_token)) {
                $table->secure_token = md5('yovel-pos-qr-'.strtolower(trim($table->table_name)));
            }
        });
    }

    /**
     * Relasi (HasMany): Satu meja bisa memiliki banyak riwayat pesanan
     * dari waktu ke waktu (setiap pelanggan yang duduk & scan QR di sana).
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * KUNCI INTEGRASI QR: route /order/{table} akan resolve pakai
     * secure_token, TIDAK PERNAH pakai id mentah di URL publik.
     */
    public function getRouteKeyName(): string
    {
        return 'secure_token';
    }
}
