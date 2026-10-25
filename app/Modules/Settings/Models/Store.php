<?php

namespace App\Modules\Settings\Models;

use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToTenant;
use App\Models\Device;
use App\Models\EodSettlement;
use App\Models\Ingredient;
use App\Models\Product;
use App\Models\StoreProductAssortment;
use App\Models\Table;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Context\TenantContext;
use App\Services\EntitlementService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * @property int $id
 * @property int $tenant_id
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
    use ScopedToTenant;
    use SoftDeletes;

    protected static function booted()
    {
        static::created(function ($model) {
            // Pre-create balance rows untuk store baru. Query ke Product/
            // Ingredient bersifat tenant-scoped, jadi operasi ini hanya aman
            // dijalankan ketika tenant context sudah ada (bukan di harness/boot).
            if (app(TenantContext::class)->getTenantId() !== (int) $model->tenant_id) {
                return;
            }

            $products = Product::withoutTenantScope()->where('tenant_id', $model->tenant_id)->get();
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

            $ingredients = Ingredient::withoutTenantScope()->where('tenant_id', $model->tenant_id)->get();
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
        'name',
        'code',
        'timezone',
        'business_date_cutoff',
        'default_channel',
        'address',
        'phone',
        'is_active',
        'is_initial',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_initial' => 'boolean',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_stores');
    }

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    public function tables(): HasMany
    {
        return $this->hasMany(Table::class);
    }

    public function eodSettlements(): HasMany
    {
        return $this->hasMany(EodSettlement::class);
    }

    public function productAssortments(): HasMany
    {
        return $this->hasMany(StoreProductAssortment::class);
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
