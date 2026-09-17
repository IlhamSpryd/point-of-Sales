<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * Model User merepresentasikan entitas pengguna (Administrator/Kasir/Pimpinan) di dalam aplikasi.
 * Mewarisi kolom autentikasi bawaan tabel users.
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, SoftDeletes;

    /**
     * Kolom-kolom yang diperbolehkan untuk diisi secara massal (Mass-assignment).
     */
    protected $fillable = ['name', 'email', 'password', 'role_id'];

    /**
     * Menjaga kolom-kolom ini tetap rahasia saat objek dipanggil menjadi Array/JSON.
     */
    protected $hidden = ['password', 'remember_token'];

    /**
     * Relasi (BelongsTo): Setiap pengguna memiliki satu hak akses atau peran (Role).
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * Relasi (HasMany): Seorang pengguna (kasir) dapat melayani atau mencatat banyak transaksi (Orders).
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Konversi tipe data otomatis (Type Casting).
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
