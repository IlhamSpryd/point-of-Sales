<?php

namespace App\Models;

use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToTenant;
use App\Services\Context\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
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
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read \App\Models\Tenant|null $tenant
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

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function users()
    {
        return $this->belongsToMany(User::class, 'user_stores');
    }
}
