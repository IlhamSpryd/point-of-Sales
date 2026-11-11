<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Models;

use App\Core\Traits\AssignsStore;
use App\Core\Traits\AssignsTenant;
use App\Models\Ingredient;
use App\Models\Product;
use App\Models\Scopes\TenantScope;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model Discrete Manufacturing Order (Perintah Kerja Manufaktur & Kitchen Prep).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $store_id
 * @property string $order_number
 * @property string $destination_type
 * @property int|null $product_id
 * @property int|null $destination_ingredient_id
 * @property numeric-string $planned_quantity
 * @property numeric-string $produced_quantity
 * @property string $status
 * @property \Illuminate\Support\Carbon|null $planned_start_date
 * @property \Illuminate\Support\Carbon|null $completed_at
 * @property string|null $notes
 * @property int|null $created_by
 * @property-read Ingredient|null $destinationIngredient
 */
#[ScopedBy(TenantScope::class)]
class ManufacturingOrder extends Model
{
    use AssignsStore;
    use AssignsTenant;
    use HasFactory;

    protected $table = 'manufacturing_orders';

    protected $fillable = [
        'tenant_id',
        'store_id',
        'order_number',
        'destination_type',
        'product_id',
        'destination_ingredient_id',
        'planned_quantity',
        'produced_quantity',
        'status',
        'planned_start_date',
        'completed_at',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'planned_quantity' => 'decimal:4',
            'produced_quantity' => 'decimal:4',
            'planned_start_date' => 'date',
            'completed_at' => 'datetime',
        ];
    }

    public function isPrepOrder(): bool
    {
        return $this->destination_type === 'ingredient';
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Bahan olahan / prep item yang dihasilkan dari perintah kerja manufaktur.
     *
     * @return BelongsTo<Ingredient, $this>
     */
    public function destinationIngredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class, 'destination_ingredient_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

