<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Model ModifierGroup merepresentasikan sebuah KATEGORI varian,
 * contoh: "Pilihan Suhu", "Tingkat Gula", "Jenis Susu".
 * Satu grup ini bisa dipakai ulang oleh banyak produk berbeda.
 */
// [OMEGA-NODE1] PATCH FOR M-02: SoftDeletes membuat ->delete() menjadi
// UPDATE deleted_at, BUKAN DELETE fisik -- sehingga FK CASCADE di
// modifiers.modifier_group_id tidak pernah terpicu lagi dari jalur admin
// biasa (ModifierManager::deleteGroup()). | 2026-09-24
class ModifierGroup extends Model
{
    use SoftDeletes;

    protected $fillable = ['name', 'selection_type', 'is_required'];

    protected function casts(): array
    {
        return [
            'is_required' => 'boolean',
        ];
    }

    // [OMEGA-NODE1] PATCH FOR M-02: saat grup di-soft-delete, ikut
    // soft-delete opsi di dalamnya agar tidak tampil "yatim" di katalog
    // meski grup induknya sudah diarsipkan.
    protected static function booted(): void
    {
        static::deleting(function (ModifierGroup $group) {
            $group->modifiers()->delete();
        });
    }

    /**
     * Relasi (HasMany): Satu grup varian punya banyak pilihan opsi di dalamnya.
     * Contoh: grup "Pilihan Suhu" punya opsi "Ice" dan "Hot".
     */
    public function modifiers(): HasMany
    {
        return $this->hasMany(Modifier::class);
    }

    /**
     * Relasi (BelongsToMany): Grup varian ini dipakai oleh produk-produk mana saja.
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'modifier_group_product');
    }
}
