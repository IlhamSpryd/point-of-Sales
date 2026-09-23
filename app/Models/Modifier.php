<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

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

    /**
     * Relasi (BelongsToMany): Bahan baku ekstra (BOM) untuk modifier ini.
     */
    public function ingredients(): BelongsToMany
    {
        return $this->belongsToMany(Ingredient::class, 'modifier_ingredients')
            ->withPivot('quantity_required')
            ->withTimestamps();
    }

    /**
     * Relasi (BelongsToMany): Rincian pesanan yang menggunakan modifier ini.
     */
    public function orderItems(): BelongsToMany
    {
        return $this->belongsToMany(OrderItem::class, 'order_item_modifiers')
            ->withPivot('price_at_time', 'qty')
            ->withTimestamps();
    }
}
