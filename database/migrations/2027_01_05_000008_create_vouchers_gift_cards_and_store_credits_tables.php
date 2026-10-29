<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        // 1. Tabel vouchers (Voucher Promosi & Kupon Diskon)
        Schema::create('vouchers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('code', 64);
            $table->string('name', 150);
            $table->unsignedBigInteger('discount_id')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->unsignedBigInteger('customer_group_id')->nullable();
            $table->string('discount_type', 20); // PERCENTAGE, FIXED_AMOUNT
            $table->decimal('discount_value', 18, 2);
            $table->decimal('max_discount_amount', 18, 2)->nullable();
            $table->decimal('min_order_amount', 18, 2)->default(0.00);
            $table->unsignedInteger('max_uses')->default(1);
            $table->unsignedInteger('max_uses_per_customer')->default(1);
            $table->unsignedInteger('used_count')->default(0);
            $table->timestamp('valid_from')->useCurrent();
            $table->timestamp('valid_until')->nullable();
            $table->string('status', 20)->default('ACTIVE'); // ACTIVE, EXPIRED, DISABLED
            $table->timestamps();

            // Multi-tenant indexes & uniqueness
            $table->unique(['tenant_id', 'id'], 'vouchers_tenant_id_id_unique');
            $table->unique(['tenant_id', 'code'], 'vouchers_tenant_code_unique');
            $table->index(['tenant_id', 'status', 'valid_from', 'valid_until'], 'vouchers_tenant_status_dates_idx');

            // Composite Foreign Keys
            $table->foreign(['tenant_id', 'customer_id'], 'vouchers_customer_composite_fk')
                ->references(['tenant_id', 'id'])->on('customers')
                ->onDelete('cascade')->onUpdate('cascade');

            $table->foreign(['tenant_id', 'customer_group_id'], 'vouchers_cust_group_composite_fk')
                ->references(['tenant_id', 'id'])->on('customer_groups')
                ->onDelete('cascade')->onUpdate('cascade');
        });

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE vouchers ADD CONSTRAINT chk_voucher_discount_type
                CHECK (discount_type IN ('PERCENTAGE', 'FIXED_AMOUNT'))");
            DB::statement("ALTER TABLE vouchers ADD CONSTRAINT chk_voucher_status
                CHECK (status IN ('ACTIVE', 'EXPIRED', 'DISABLED'))");
            DB::statement('ALTER TABLE vouchers ADD CONSTRAINT chk_voucher_amounts
                CHECK (discount_value >= 0 AND min_order_amount >= 0)');
        }

        // 2. Tabel gift_cards (Kartu Hadiah / Prepaid Value Instrument)
        Schema::create('gift_cards', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('issued_store_id')->nullable();
            $table->string('card_number', 64);
            $table->string('pin_hash', 255)->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->decimal('initial_balance', 18, 2);
            $table->decimal('current_balance', 18, 2);
            $table->string('status', 20)->default('ACTIVE'); // ACTIVE, DEPLETED, EXPIRED, BLOCKED
            $table->timestamp('expires_at')->nullable();
            $table->unsignedBigInteger('issued_by_order_id')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id'], 'gift_cards_tenant_id_id_unique');
            $table->unique(['tenant_id', 'card_number'], 'gift_cards_tenant_card_number_unique');
            $table->index(['tenant_id', 'status'], 'gift_cards_tenant_status_idx');
            $table->index(['tenant_id', 'customer_id'], 'gift_cards_tenant_cust_idx');

            $table->foreign(['tenant_id', 'issued_store_id'], 'gift_cards_store_composite_fk')
                ->references(['tenant_id', 'id'])->on('stores')
                ->onDelete('cascade')->onUpdate('cascade');

            $table->foreign(['tenant_id', 'customer_id'], 'gift_cards_customer_composite_fk')
                ->references(['tenant_id', 'id'])->on('customers')
                ->onDelete('cascade')->onUpdate('cascade');
        });

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE gift_cards ADD CONSTRAINT chk_gift_card_status
                CHECK (status IN ('ACTIVE', 'DEPLETED', 'EXPIRED', 'BLOCKED'))");
            DB::statement('ALTER TABLE gift_cards ADD CONSTRAINT chk_gift_card_balance
                CHECK (current_balance >= 0 AND current_balance <= initial_balance AND initial_balance >= 0)');
        }

        // 3. Tabel gift_card_ledgers (Buku Besar Mutasi Kartu Hadiah)
        Schema::create('gift_card_ledgers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('gift_card_id');
            $table->unsignedBigInteger('store_id');
            $table->string('entry_type', 40); // ISSUE, REDEEM, REFUND_REVERSAL, EXPIRE_WRITE_OFF
            $table->decimal('amount', 18, 2);
            $table->decimal('balance_after', 18, 2);
            $table->string('reference_type', 60)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('idempotency_key', 100);
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['tenant_id', 'id'], 'gcl_tenant_id_id_unique');
            $table->unique(['tenant_id', 'idempotency_key'], 'gcl_tenant_idempotency_unique');
            $table->index(['tenant_id', 'gift_card_id', 'id'], 'gcl_tenant_gc_id_idx');
            $table->index(['tenant_id', 'store_id'], 'gcl_tenant_store_idx');

            $table->foreign(['tenant_id', 'gift_card_id'], 'gcl_gc_composite_fk')
                ->references(['tenant_id', 'id'])->on('gift_cards')
                ->onDelete('restrict')->onUpdate('cascade');

            $table->foreign(['tenant_id', 'store_id'], 'gcl_store_composite_fk')
                ->references(['tenant_id', 'id'])->on('stores')
                ->onDelete('restrict')->onUpdate('cascade');

            $table->foreign('actor_user_id', 'gcl_actor_user_fk')
                ->references('id')->on('users')
                ->onDelete('set null');
        });

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE gift_card_ledgers ADD CONSTRAINT chk_gcl_entry_type
                CHECK (entry_type IN ('ISSUE', 'REDEEM', 'REFUND_REVERSAL', 'EXPIRE_WRITE_OFF'))");
            DB::statement('ALTER TABLE gift_card_ledgers ADD CONSTRAINT chk_gcl_balance_after
                CHECK (balance_after >= 0)');
        }

        // 4. Tabel store_credit_accounts (Akun Kredit Toko / Saldo Deposit Pelanggan)
        Schema::create('store_credit_accounts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('customer_id');
            $table->decimal('current_balance', 18, 2)->default(0.00);
            $table->string('status', 20)->default('ACTIVE'); // ACTIVE, FROZEN, CLOSED
            $table->timestamps();

            $table->unique(['tenant_id', 'id'], 'sca_tenant_id_id_unique');
            $table->unique(['tenant_id', 'customer_id'], 'sca_tenant_customer_unique');
            $table->index(['tenant_id', 'status'], 'sca_tenant_status_idx');

            $table->foreign(['tenant_id', 'customer_id'], 'sca_customer_composite_fk')
                ->references(['tenant_id', 'id'])->on('customers')
                ->onDelete('cascade')->onUpdate('cascade');
        });

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE store_credit_accounts ADD CONSTRAINT chk_sca_status
                CHECK (status IN ('ACTIVE', 'FROZEN', 'CLOSED'))");
            DB::statement('ALTER TABLE store_credit_accounts ADD CONSTRAINT chk_sca_current_balance
                CHECK (current_balance >= 0)');
        }

        // 5. Tabel store_credit_ledgers (Buku Besar Mutasi Kredit Toko)
        Schema::create('store_credit_ledgers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('store_credit_account_id');
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('store_id')->nullable();
            $table->string('entry_type', 40); // CREDIT_NOTE_ISSUE, OVERPAYMENT_ISSUE, REDEEM_ORDER, REDEEM_INVOICE, REVERSAL, EXPIRE
            $table->decimal('amount', 18, 2);
            $table->decimal('balance_after', 18, 2);
            $table->string('reference_type', 60)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('idempotency_key', 100);
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['tenant_id', 'id'], 'scl_tenant_id_id_unique');
            $table->unique(['tenant_id', 'idempotency_key'], 'scl_tenant_idempotency_unique');
            $table->index(['tenant_id', 'store_credit_account_id', 'id'], 'scl_tenant_sca_idx');
            $table->index(['tenant_id', 'customer_id', 'id'], 'scl_tenant_cust_idx');

            $table->foreign(['tenant_id', 'store_credit_account_id'], 'scl_sca_composite_fk')
                ->references(['tenant_id', 'id'])->on('store_credit_accounts')
                ->onDelete('restrict')->onUpdate('cascade');

            $table->foreign(['tenant_id', 'customer_id'], 'scl_cust_composite_fk')
                ->references(['tenant_id', 'id'])->on('customers')
                ->onDelete('restrict')->onUpdate('cascade');

            $table->foreign('actor_user_id', 'scl_actor_user_fk')
                ->references('id')->on('users')
                ->onDelete('set null');
        });

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE store_credit_ledgers ADD CONSTRAINT chk_scl_entry_type
                CHECK (entry_type IN ('CREDIT_NOTE_ISSUE', 'OVERPAYMENT_ISSUE', 'REDEEM_ORDER', 'REDEEM_INVOICE', 'REVERSAL', 'EXPIRE'))");
            DB::statement('ALTER TABLE store_credit_ledgers ADD CONSTRAINT chk_scl_balance_after
                CHECK (balance_after >= 0)');
        }

        // 6. Seed Permissions Baru Modul 6 (Loyalty & Value Instruments)
        if (Schema::hasTable('permissions')) {
            $permissions = [
                ['permission_code' => 'loyalty.view', 'description' => 'Melihat saldo poin loyalitas dan riwayat mutasi buku besar poin'],
                ['permission_code' => 'loyalty.adjust', 'description' => 'Melakukan penyesuaian manual (adjustment/bonus) saldo poin loyalitas'],
                ['permission_code' => 'vouchers.manage', 'description' => 'Membuat, mengubah, dan menonaktifkan kode voucher/kupon diskon'],
                ['permission_code' => 'gift_cards.issue', 'description' => 'Menerbitkan kartu hadiah (Gift Card) baru'],
                ['permission_code' => 'gift_cards.void', 'description' => 'Membatalkan (void/block) kartu hadiah'],
                ['permission_code' => 'store_credits.view', 'description' => 'Melihat akun saldo store credit pelanggan'],
                ['permission_code' => 'store_credits.issue', 'description' => 'Menerbitkan penambahan saldo kredit toko (Store Credit)'],
            ];

            foreach ($permissions as $p) {
                DB::table('permissions')->insertOrIgnore([
                    'permission_code' => $p['permission_code'],
                    'description' => $p['description'],
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('store_credit_ledgers');
        Schema::dropIfExists('store_credit_accounts');
        Schema::dropIfExists('gift_card_ledgers');
        Schema::dropIfExists('gift_cards');
        Schema::dropIfExists('vouchers');
    }
};
