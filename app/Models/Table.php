<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Model Table merepresentasikan satu meja fisik di coffeeshop.
 * Setiap meja punya QR Code unik (dibuat dari secure_token) yang ditempel
 * di atas meja untuk memulai sesi pemesanan self-order.
 */
class Table extends Model
{
    protected $table = 'tables'; // eksplisit, agar tidak ambigu dengan nama class 'Table'

    protected $fillable = ['table_number', 'status'];

    protected function casts(): array
    {
        return [
            // Tidak perlu cast khusus untuk enum status di sini,
            // Laravel akan menganggapnya string biasa saat dibaca.
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
            $table->secure_token ??= (string) Str::uuid();
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
