<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Model ModifierGroup merepresentasikan sebuah KATEGORI varian,
 * contoh: "Pilihan Suhu", "Tingkat Gula", "Jenis Susu".
 * Satu grup ini bisa dipakai ulang oleh banyak produk berbeda.
 */
class ModifierGroup extends Model
{
    protected $fillable = ['name', 'selection_type', 'is_required'];

    protected function casts(): array
    {
        return [
            'is_required' => 'boolean',
        ];
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
