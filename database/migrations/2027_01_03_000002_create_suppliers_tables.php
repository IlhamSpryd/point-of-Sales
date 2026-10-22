<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Modul 5 (Fase 1) — Migrasi Data Induk Pemasok (Suppliers), Kontak (Supplier Contacts),
 * Katalog Item Pemasok (Supplier Products), dan Pendaftaran RBAC Permissions.
 *
 * Mengikuti spesifikasi Section 7.2 & 19 docs/implementationPlanModul5.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        // 1. Tabel suppliers
        if (! Schema::hasTable('suppliers')) {
            Schema::create('suppliers', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->string('code', 32);
                $table->string('name', 255);
                $table->string('normalized_name', 255);
                $table->string('email', 255)->nullable();
                $table->string('phone', 50)->nullable();
                $table->string('tax_id', 50)->nullable();
                $table->text('address')->nullable();
                $table->integer('payment_terms_days')->default(0);
                $table->string('currency_code', 3)->default('IDR');
                $table->string('status', 20)->default('active');
                $table->text('notes')->nullable();
                $table->unsignedBigInteger('created_by_user_id')->nullable();
                $table->unsignedBigInteger('updated_by_user_id')->nullable();
                $table->softDeletes();
                $table->timestamps();

                $table->unique(['tenant_id', 'id'], 'suppliers_tenant_id_id_unique');
                $table->unique(['tenant_id', 'code'], 'suppliers_tenant_code_unique');

                $table->foreign('tenant_id', 'suppliers_tenant_fk')
                    ->references('id')->on('tenants')
                    ->onDelete('cascade')->onUpdate('cascade');

                $table->foreign('created_by_user_id', 'suppliers_created_by_user_fk')
                    ->references('id')->on('users')
                    ->onDelete('set null')->onUpdate('cascade');

                $table->foreign('updated_by_user_id', 'suppliers_updated_by_user_fk')
                    ->references('id')->on('users')
                    ->onDelete('set null')->onUpdate('cascade');

                $table->index(['tenant_id', 'status', 'name'], 'suppliers_tenant_status_name_idx');
                $table->index(['tenant_id', 'normalized_name'], 'suppliers_tenant_normalized_name_idx');
            });

            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                DB::statement('ALTER TABLE suppliers ADD CONSTRAINT chk_suppliers_payment_terms CHECK (payment_terms_days >= 0)');
                DB::statement("ALTER TABLE suppliers ADD CONSTRAINT chk_suppliers_status CHECK (status IN ('active', 'on_hold', 'inactive', 'archived'))");
            }
        }

        // 2. Tabel supplier_contacts
        if (! Schema::hasTable('supplier_contacts')) {
            Schema::create('supplier_contacts', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('supplier_id');
                $table->string('name', 255);
                $table->string('title', 100)->nullable();
                $table->string('email', 255)->nullable();
                $table->string('phone', 50)->nullable();
                $table->boolean('is_primary')->default(false);
                $table->text('notes')->nullable();
                $table->softDeletes();
                $table->timestamps();

                $table->unique(['tenant_id', 'id'], 'supplier_contacts_tenant_id_id_unique');

                $table->foreign(['tenant_id', 'supplier_id'], 'supplier_contacts_supplier_composite_fk')
                    ->references(['tenant_id', 'id'])->on('suppliers')
                    ->onDelete('cascade')->onUpdate('cascade');

                $table->index(['tenant_id', 'supplier_id', 'is_primary'], 'supp_contacts_tenant_supp_primary_idx');
            });
        }

        // 3. Tabel supplier_products
        if (! Schema::hasTable('supplier_products')) {
            Schema::create('supplier_products', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('supplier_id');
                $table->string('purchasable_type', 20); // 'product' | 'ingredient'
                $table->unsignedBigInteger('purchasable_id');
                $table->string('supplier_sku', 100)->nullable();
                $table->string('purchase_uom', 32);
                $table->decimal('uom_conversion_factor', 18, 6)->default(1.000000);
                $table->decimal('standard_price', 18, 4)->default(0.0000);
                $table->decimal('last_purchase_price', 18, 4)->nullable();
                $table->integer('lead_time_days')->default(1);
                $table->decimal('min_order_qty', 18, 4)->default(1.0000);
                $table->decimal('order_multiple_qty', 18, 4)->default(1.0000);
                $table->boolean('is_preferred')->default(false);
                $table->boolean('is_active')->default(true);
                $table->softDeletes();
                $table->timestamps();

                $table->unique(['tenant_id', 'id'], 'supplier_products_tenant_id_id_unique');
                $table->unique(
                    ['tenant_id', 'supplier_id', 'purchasable_type', 'purchasable_id', 'purchase_uom'],
                    'sp_tenant_supp_item_uom_unique'
                );

                $table->foreign(['tenant_id', 'supplier_id'], 'supplier_products_supplier_composite_fk')
                    ->references(['tenant_id', 'id'])->on('suppliers')
                    ->onDelete('cascade')->onUpdate('cascade');

                $table->index(['tenant_id', 'purchasable_type', 'purchasable_id', 'is_preferred'], 'sp_tenant_item_preferred_idx');
                $table->index(['tenant_id', 'supplier_id', 'is_active'], 'sp_tenant_supp_active_idx');
            });

            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                DB::statement('ALTER TABLE supplier_products ADD CONSTRAINT chk_sp_conversion_factor CHECK (uom_conversion_factor > 0)');
                DB::statement('ALTER TABLE supplier_products ADD CONSTRAINT chk_sp_standard_price CHECK (standard_price >= 0)');
                DB::statement('ALTER TABLE supplier_products ADD CONSTRAINT chk_sp_lead_time_days CHECK (lead_time_days >= 0)');
                DB::statement('ALTER TABLE supplier_products ADD CONSTRAINT chk_sp_min_order_qty CHECK (min_order_qty > 0)');
                DB::statement('ALTER TABLE supplier_products ADD CONSTRAINT chk_sp_order_multiple_qty CHECK (order_multiple_qty > 0)');
                DB::statement("ALTER TABLE supplier_products ADD CONSTRAINT chk_sp_purchasable_type CHECK (purchasable_type IN ('product', 'ingredient'))");
            }
        }

        // 4. Seed Permissions M5 untuk Supplier
        if (Schema::hasTable('permissions')) {
            $permissions = [
                ['permission_code' => 'purchasing.supplier.view', 'description' => 'Melihat daftar dan profil pemasok'],
                ['permission_code' => 'purchasing.supplier.create', 'description' => 'Membuat data induk pemasok baru'],
                ['permission_code' => 'purchasing.supplier.update', 'description' => 'Mengubah informasi data pemasok dan kontak'],
                ['permission_code' => 'purchasing.supplier.archive', 'description' => 'Mengarsipkan data pemasok'],
                ['permission_code' => 'purchasing.supplier.manage_catalog', 'description' => 'Mengelola katalog produk & harga pemasok'],
            ];

            foreach ($permissions as $perm) {
                DB::table('permissions')->insertOrIgnore([
                    'permission_code' => $perm['permission_code'],
                    'description' => $perm['description'],
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_products');
        Schema::dropIfExists('supplier_contacts');
        Schema::dropIfExists('suppliers');
    }
};
