<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Model Modifier merepresentasikan SATU opsi pilihan di dalam sebuah grup varian.
 * Contoh: "Ice", "Hot", "Less Sugar", "Oat Milk" (+Rp5.000).
 */
// [OMEGA-NODE1] PATCH FOR M-02 (lihat ModifierGroup.php untuk alasan penuh). | 2026-09-24
class Modifier extends Model
{
    use SoftDeletes;

    protected $fillable = ['modifier_group_id', 'name', 'extra_price', 'is_default', 'is_active'];

    protected function casts(): array
    {
        return [
            'extra_price' => 'integer',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
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
