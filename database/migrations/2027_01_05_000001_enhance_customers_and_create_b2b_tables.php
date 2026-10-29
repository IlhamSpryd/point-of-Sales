<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Modul 6 (Fase 1) — Customer Master Data, B2B Profile, Segmentasi, Multi-Alamat, Multi-Kontak, Merge Log, dan RBAC.
 *
 * Mengikuti spesifikasi Section 9, 11.1, 13, dan 19 pada docs/audit/implementationPlanModul6.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        // 1. Tabel customer_groups
        if (! Schema::hasTable('customer_groups')) {
            Schema::create('customer_groups', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->string('code', 50);
                $table->string('name', 255);
                $table->text('description')->nullable();
                $table->unsignedBigInteger('default_price_list_id')->nullable();
                $table->decimal('default_discount_rate', 5, 2)->default(0.00);
                $table->boolean('is_b2b_default')->default(false);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'id'], 'customer_groups_tenant_id_id_unique');
                $table->unique(['tenant_id', 'code'], 'customer_groups_tenant_code_unique');

                $table->foreign('tenant_id', 'customer_groups_tenant_fk')
                    ->references('id')->on('tenants')
                    ->onDelete('cascade')->onUpdate('cascade');

                if (Schema::hasTable('price_lists')) {
                    $table->foreign('default_price_list_id', 'cg_price_list_fk')
                        ->references('id')->on('price_lists')
                        ->onDelete('set null')->onUpdate('cascade');
                }

                $table->index(['tenant_id', 'is_active', 'name'], 'cg_tenant_active_name_idx');
                $table->index(['tenant_id', 'default_price_list_id'], 'cg_tenant_price_list_idx');
            });

            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                DB::statement('ALTER TABLE customer_groups ADD CONSTRAINT chk_cg_discount CHECK (default_discount_rate >= 0 AND default_discount_rate <= 100)');
            }
        }

        // 2. Perluas tabel customers
        if (Schema::hasTable('customers')) {
            Schema::table('customers', function (Blueprint $table) {
                if (! Schema::hasColumn('customers', 'customer_type')) {
                    $table->string('customer_type', 20)->default('INDIVIDUAL')->after('name');
                }
                if (! Schema::hasColumn('customers', 'customer_group_id')) {
                    $table->unsignedBigInteger('customer_group_id')->nullable()->after('customer_type');
                }
                if (! Schema::hasColumn('customers', 'company_name')) {
                    $table->string('company_name', 255)->nullable()->after('customer_group_id');
                }
                if (! Schema::hasColumn('customers', 'tax_id')) {
                    $table->string('tax_id', 64)->nullable()->after('company_name');
                }
                if (! Schema::hasColumn('customers', 'business_registration_no')) {
                    $table->string('business_registration_no', 64)->nullable()->after('tax_id');
                }
                if (! Schema::hasColumn('customers', 'default_store_id')) {
                    $table->unsignedBigInteger('default_store_id')->nullable()->after('business_registration_no');
                }
                if (! Schema::hasColumn('customers', 'sales_rep_user_id')) {
                    $table->unsignedBigInteger('sales_rep_user_id')->nullable()->after('default_store_id');
                }
                if (! Schema::hasColumn('customers', 'price_list_id')) {
                    $table->unsignedBigInteger('price_list_id')->nullable()->after('sales_rep_user_id');
                }
                if (! Schema::hasColumn('customers', 'is_loyalty_eligible')) {
                    $table->boolean('is_loyalty_eligible')->default(true)->after('is_active');
                }
                if (! Schema::hasColumn('customers', 'merged_into_customer_id')) {
                    $table->unsignedBigInteger('merged_into_customer_id')->nullable()->after('is_loyalty_eligible');
                }
            });

            Schema::table('customers', function (Blueprint $table) {
                $table->foreign('customer_group_id', 'customers_group_fk')
                    ->references('id')->on('customer_groups')
                    ->onDelete('set null')->onUpdate('cascade');

                if (Schema::hasTable('stores')) {
                    $table->foreign('default_store_id', 'customers_default_store_fk')
                        ->references('id')->on('stores')
                        ->onDelete('set null')->onUpdate('cascade');
                }

                if (Schema::hasTable('users')) {
                    $table->foreign('sales_rep_user_id', 'customers_sales_rep_fk')
                        ->references('id')->on('users')
                        ->onDelete('set null')->onUpdate('cascade');
                }

                if (Schema::hasTable('price_lists')) {
                    $table->foreign('price_list_id', 'customers_price_list_fk')
                        ->references('id')->on('price_lists')
                        ->onDelete('set null')->onUpdate('cascade');
                }

                $table->foreign('merged_into_customer_id', 'customers_merged_into_fk')
                    ->references('id')->on('customers')
                    ->onDelete('set null')->onUpdate('cascade');

                $table->index(['tenant_id', 'customer_type', 'is_active'], 'customers_tenant_type_active_idx');
                $table->index(['tenant_id', 'customer_group_id'], 'customers_tenant_group_idx');
                $table->index(['tenant_id', 'default_store_id'], 'customers_tenant_store_idx');
                $table->index(['tenant_id', 'merged_into_customer_id'], 'customers_tenant_merged_into_idx');
            });

            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                DB::statement("ALTER TABLE customers ADD CONSTRAINT chk_customers_type CHECK (customer_type IN ('INDIVIDUAL', 'COMPANY'))");
            }
        }

        // 3. Tabel customer_addresses
        if (! Schema::hasTable('customer_addresses')) {
            Schema::create('customer_addresses', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('customer_id');
                $table->string('address_type', 20)->default('SHIPPING'); // 'BILLING', 'SHIPPING', 'BOTH'
                $table->string('label', 100)->nullable();
                $table->string('recipient_name', 255)->nullable();
                $table->string('phone', 50)->nullable();
                $table->text('address_line1');
                $table->text('address_line2')->nullable();
                $table->string('city', 100)->nullable();
                $table->string('province', 100)->nullable();
                $table->string('postal_code', 20)->nullable();
                $table->string('country_code', 10)->default('ID');
                $table->boolean('is_default_billing')->default(false);
                $table->boolean('is_default_shipping')->default(false);
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'id'], 'customer_addresses_tenant_id_id_unique');

                $table->foreign(['tenant_id', 'customer_id'], 'ca_tenant_customer_composite_fk')
                    ->references(['tenant_id', 'id'])->on('customers')
                    ->onDelete('cascade')->onUpdate('cascade');

                $table->index(['tenant_id', 'customer_id', 'address_type'], 'ca_tenant_cust_type_idx');
                $table->index(['tenant_id', 'customer_id', 'is_default_shipping'], 'ca_tenant_cust_shipping_idx');
                $table->index(['tenant_id', 'customer_id', 'is_default_billing'], 'ca_tenant_cust_billing_idx');
            });

            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                DB::statement("ALTER TABLE customer_addresses ADD CONSTRAINT chk_ca_address_type CHECK (address_type IN ('BILLING', 'SHIPPING', 'BOTH'))");
            }
        }

        // 4. Tabel customer_contacts
        if (! Schema::hasTable('customer_contacts')) {
            Schema::create('customer_contacts', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('customer_id');
                $table->string('name', 255);
                $table->string('role_title', 100)->nullable();
                $table->string('email', 255)->nullable();
                $table->string('phone', 50)->nullable();
                $table->boolean('is_primary')->default(false);
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'id'], 'customer_contacts_tenant_id_id_unique');

                $table->foreign(['tenant_id', 'customer_id'], 'cc_tenant_customer_composite_fk')
                    ->references(['tenant_id', 'id'])->on('customers')
                    ->onDelete('cascade')->onUpdate('cascade');

                $table->index(['tenant_id', 'customer_id', 'is_primary'], 'cc_tenant_cust_primary_idx');
            });
        }

        // 5. Tabel customer_merge_logs
        if (! Schema::hasTable('customer_merge_logs')) {
            Schema::create('customer_merge_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('source_customer_id');
                $table->unsignedBigInteger('target_customer_id');
                $table->unsignedBigInteger('merged_by_user_id')->nullable();
                $table->text('reason')->nullable();
                $table->json('reassigned_records_json')->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->unique(['tenant_id', 'id'], 'cml_tenant_id_id_unique');

                $table->foreign(['tenant_id', 'target_customer_id'], 'cml_tenant_target_cust_composite_fk')
                    ->references(['tenant_id', 'id'])->on('customers')
                    ->onDelete('cascade')->onUpdate('cascade');

                if (Schema::hasTable('users')) {
                    $table->foreign('merged_by_user_id', 'cml_merged_by_user_fk')
                        ->references('id')->on('users')
                        ->onDelete('set null')->onUpdate('cascade');
                }

                $table->index(['tenant_id', 'source_customer_id'], 'cml_tenant_source_cust_idx');
                $table->index(['tenant_id', 'target_customer_id'], 'cml_tenant_target_cust_idx');
            });
        }

        // 6. Seed RBAC permissions Modul 6
        if (Schema::hasTable('permissions')) {
            $permissions = [
                ['permission_code' => 'customers.view', 'description' => 'Melihat data profil dan daftar pelanggan'],
                ['permission_code' => 'customers.create', 'description' => 'Membuat pelanggan baru (B2C & B2B)'],
                ['permission_code' => 'customers.update', 'description' => 'Mengubah profil, alamat, dan kontak pelanggan'],
                ['permission_code' => 'customers.delete', 'description' => 'Menghapus atau menonaktifkan pelanggan'],
                ['permission_code' => 'customers.merge', 'description' => 'Menggabungkan pelanggan duplikat'],
                ['permission_code' => 'customers.manage_groups', 'description' => 'Mengelola grup dan segmentasi pelanggan'],
                ['permission_code' => 'customers.export_pii', 'description' => 'Mengekspor data PII pelanggan'],
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
        $driver = DB::connection()->getDriverName();

        if (Schema::hasTable('customer_merge_logs')) {
            Schema::dropIfExists('customer_merge_logs');
        }

        if (Schema::hasTable('customer_contacts')) {
            Schema::dropIfExists('customer_contacts');
        }

        if (Schema::hasTable('customer_addresses')) {
            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                try {
                    DB::statement('ALTER TABLE customer_addresses DROP CONSTRAINT chk_ca_address_type');
                } catch (Throwable) {
                }
            }
            Schema::dropIfExists('customer_addresses');
        }

        if (Schema::hasTable('customers')) {
            Schema::table('customers', function (Blueprint $table) {
                $table->dropForeign('customers_group_fk');
                if (Schema::hasTable('stores')) {
                    $table->dropForeign('customers_default_store_fk');
                }
                if (Schema::hasTable('users')) {
                    $table->dropForeign('customers_sales_rep_fk');
                }
                if (Schema::hasTable('price_lists')) {
                    $table->dropForeign('customers_price_list_fk');
                }
                $table->dropForeign('customers_merged_into_fk');

                $table->dropIndex('customers_tenant_type_active_idx');
                $table->dropIndex('customers_tenant_group_idx');
                $table->dropIndex('customers_tenant_store_idx');
                $table->dropIndex('customers_tenant_merged_into_idx');
            });

            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                try {
                    DB::statement('ALTER TABLE customers DROP CONSTRAINT chk_customers_type');
                } catch (Throwable) {
                }
            }

            Schema::table('customers', function (Blueprint $table) {
                $table->dropColumn([
                    'customer_type',
                    'customer_group_id',
                    'company_name',
                    'tax_id',
                    'business_registration_no',
                    'default_store_id',
                    'sales_rep_user_id',
                    'price_list_id',
                    'is_loyalty_eligible',
                    'merged_into_customer_id',
                ]);
            });
        }

        if (Schema::hasTable('customer_groups')) {
            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                try {
                    DB::statement('ALTER TABLE customer_groups DROP CONSTRAINT chk_cg_discount');
                } catch (Throwable) {
                }
            }
            Schema::dropIfExists('customer_groups');
        }
    }
};
