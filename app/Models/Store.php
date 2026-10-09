<?php

namespace App\Models;

use App\Services\Context\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class Store extends Model
{
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
        'address',
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
