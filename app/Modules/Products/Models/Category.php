<?php

namespace App\Modules\Products\Models;

use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToTenant;
use App\Models\Product;
use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * Model Category berguna untuk menyimpan klasifikasi tipe
 * pada katalog sistem produk jualan (contoh: Minuman, Makanan Ringan).
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $category_name
 * @property string|null $category_code
 * @property int|null $parent_id
 * @property string|null $description
 * @property int $sort_order
 * @property bool $is_active
 * @property-read Category|null $parent
 * @property int|null $name_uniqueness_key
 */
class Category extends Model
{
    use AssignsTenant;

    /** @use HasFactory<CategoryFactory> */
    use HasFactory;

    use ScopedToTenant;
    use SoftDeletes;

    /**
     * Komponen isian kolom database kategori.
     */
    protected $fillable = [
        'tenant_id',
        'category_name',
        'name',
        'category_code',
        'parent_id',
        'description',
        'sort_order',
        'is_active',
    ];

    public function setNameAttribute(mixed $value): void
    {
        $this->attributes['category_name'] = $value;
    }

    public function getNameAttribute(): ?string
    {
        return $this->attributes['category_name'] ?? null;
    }

    public function setSlugAttribute(mixed $value): void
    {
        // Table categories does not have a slug column; virtual attribute ignored during insertion
    }

    public function getSlugAttribute(): ?string
    {
        return isset($this->attributes['category_name']) ? Str::slug((string) $this->attributes['category_name']) : null;
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    protected static function booted()
    {
        static::creating(function ($model) {
            if (empty($model->category_code)) {
                $latest = static::withoutTenantScope()->latest('id')->first();
                $nextId = $latest ? $latest->id + 1 : 1;
                $model->category_code = 'CAT-'.$model->tenant_id.'-'.str_pad((string) $nextId, 3, '0', STR_PAD_LEFT);
            }
        });
    }

    /**
     * Relasi (BelongsTo): Kategori induk (jika hierarkis).
     *
     * @return BelongsTo<Category, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    /**
     * Relasi (HasMany): Subkategori turunan.
     *
     * @return HasMany<Category, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_id')->orderBy('sort_order');
    }

    /**
     * Relasi (HasMany): Sebuah kategori lazimnya memayungi banyak daftar produk.
     *
     * @return HasMany<Product, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /**
     * Scope: Kategori aktif saja.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope: Kategori tingkat teratas (tanpa parent).
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeRoot(Builder $query): Builder
    {
        return $query->whereNull('parent_id');
    }
}
