<?php

namespace App\Modules\Organization\Models;

use App\Core\Context\TenantContext;
use App\Core\Traits\AssignsTenant;
use App\Core\Traits\BelongsToCompany;
use App\Core\Traits\ScopedToTenant;
use App\Modules\Subscription\Services\EntitlementService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int|null $company_id
 * @property string $name
 * @property string|null $code
 * @property string $timezone
 * @property string $business_date_cutoff
 * @property string $default_channel
 * @property string|null $address
 * @property string|null $phone
 * @property bool $is_active
 * @property bool $is_initial
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Tenant|null $tenant
 */
class Store extends Model
{
    use AssignsTenant;
    use BelongsToCompany;
    use ScopedToTenant;
    use SoftDeletes;

    protected static function booted()
    {
        static::created(function ($model) {
            // Auto-create default operational unit for this store
            if (Schema::hasTable('operational_units')) {
                DB::table('operational_units')->insertOrIgnore([
                    'tenant_id' => $model->tenant_id,
                    'store_id' => $model->id,
                    'name' => 'Main Operational Unit',
                    'code' => 'MAIN',
                    'unit_type' => 'checkout_retail',
                    'is_default' => true,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            // Ingredient bersifat tenant-scoped, jadi operasi ini hanya aman
            // dijalankan ketika tenant context sudah ada (bukan di harness/boot).
            if (app(TenantContext::class)->getTenantId() !== (int) $model->tenant_id) {
                return;
            }

            $products = DB::table('products')->where('tenant_id', $model->tenant_id)->get();
            foreach ($products as $product) {
                DB::table('product_stock_balances')->insertOrIgnore([
                    'tenant_id' => $model->tenant_id,
                    'store_id' => $model->id,
                    'product_id' => $product->id,
                    'quantity' => 0,
                    'baseline_quantity' => 0,
                    'baseline_movement_id' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $ingredients = DB::table('ingredients')->where('tenant_id', $model->tenant_id)->get();
            foreach ($ingredients as $ingredient) {
                DB::table('ingredient_stock_balances')->insertOrIgnore([
                    'tenant_id' => $model->tenant_id,
                    'store_id' => $model->id,
                    'ingredient_id' => $ingredient->id,
                    'quantity' => 0,
                    'baseline_quantity' => 0,
                    'baseline_movement_id' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });
    }

    protected $fillable = [
        'tenant_id',
        'company_id',
        'name',
        'code',
        'timezone',
        'business_date_cutoff',
        'store_vertical',
        'default_channel',
        'address',
        'phone',
        'is_active',
        'is_initial',
    ];

    protected $casts = [
        'company_id' => 'integer',
        'is_active' => 'boolean',
        'is_initial' => 'boolean',
    ];

    public function setStatusAttribute(mixed $value): void
    {
        $this->attributes['is_active'] = ($value === 'active' || $value === true || $value === 1);
    }

    public function getStatusAttribute(): string
    {
        return $this->is_active ? 'active' : 'inactive';
    }

    public function effectiveVertical(): string
    {
        if (! empty($this->store_vertical) && in_array($this->store_vertical, ['fnb', 'retail', 'hybrid'], true)) {
            return $this->store_vertical;
        }

        return $this->tenant?->effectiveVertical() ?? 'hybrid';
    }

    public function supportsFnb(): bool
    {
        return in_array($this->effectiveVertical(), ['fnb', 'hybrid'], true);
    }

    public function supportsRetail(): bool
    {
        return in_array($this->effectiveVertical(), ['retail', 'hybrid'], true);
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_stores');
    }

    /**
     * @return HasMany<Device, $this>
     */
    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    /**
     * @return HasMany<\App\Modules\Sales\Models\Table, $this>
     */
    public function tables(): HasMany
    {
        return $this->hasMany(\App\Modules\Sales\Models\Table::class);
    }

    /**
     * @return HasMany<\App\Modules\Sales\Models\EodSettlement, $this>
     */
    public function eodSettlements(): HasMany
    {
        return $this->hasMany(\App\Modules\Sales\Models\EodSettlement::class);
    }

    /**
     * @return HasMany<\App\Modules\Catalog\Models\StoreProductAssortment, $this>
     */
    public function productAssortments(): HasMany
    {
        return $this->hasMany(\App\Modules\Catalog\Models\StoreProductAssortment::class);
    }

    /**
     * @return HasMany<OperationalUnit, $this>
     */
    public function operationalUnits(): HasMany
    {
        return $this->hasMany(OperationalUnit::class);
    }

    /**
     * @return HasMany<UserStoreRole, $this>
     */
    public function storeRoles(): HasMany
    {
        return $this->hasMany(UserStoreRole::class);
    }

    /**
     * @return HasMany<StoreCapability, $this>
     */
    public function capabilities(): HasMany
    {
        return $this->hasMany(StoreCapability::class);
    }

    public function isTablesEnabled(): bool
    {
        return app(EntitlementService::class)->isTablesEnabled((int) $this->tenant_id, (int) $this->id);
    }

    public function isSelfOrderEnabled(): bool
    {
        return app(EntitlementService::class)->isSelfOrderEnabled((int) $this->tenant_id, (int) $this->id);
    }
}
