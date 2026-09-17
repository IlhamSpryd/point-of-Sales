<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model Modifier merepresentasikan SATU opsi pilihan di dalam sebuah grup varian.
 * Contoh: "Ice", "Hot", "Less Sugar", "Oat Milk" (+Rp5.000).
 */
class Modifier extends Model
{
    protected $fillable = ['modifier_group_id', 'name', 'extra_price', 'is_default'];

    protected function casts(): array
    {
        return [
            'extra_price' => 'integer',
            'is_default' => 'boolean',
        ];
    }

    /**
     * Relasi (BelongsTo): Setiap opsi pasti tergabung ke satu grup varian induknya.
     */
    public function modifierGroup(): BelongsTo
    {
        return $this->belongsTo(ModifierGroup::class);
    }
}
