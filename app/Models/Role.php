<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Model Role digunakan untuk menyimpan hak akses level akun
 * seperti "Administrator", "Kasir", atau "Pimpinan".
 */
class Role extends Model
{
    /**
     * Kolom pada tabel roles yang diizinkan diisi massal.
     */
    protected $fillable = ['name'];

    /**
     * Relasi (HasMany): Sebuah Role dapat menempel pada banyak akun Pengguna (User).
     */
    public function users(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(User::class);
    }
}
