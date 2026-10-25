<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Modul 5 (Fase 2) — Migrasi Purchase Requests (PR), Baris PR,
 * Aturan Persetujuan Berjenjang (Purchase Approval Rules),
 * Permintaan Persetujuan (Purchase Approval Requests),
 * dan Log Keputusan Persetujuan Bersifat Append-Only (Purchase Approval Logs).
 *
 * Mengikuti spesifikasi Section 6.2, 7.2, 8.2, dan 19 docs/implementationPlanModul5.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        // 1. Tabel purchase_requests
        if (! Schema::hasTable('purchase_requests')) {
            Schema::create('purchase_requests', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('store_id');
                $table->string('pr_number', 32);
                $table->unsignedBigInteger('requested_by_user_id');
                $table->date('required_by_date');
                $table->string('status', 20)->default('draft');
                $table->string('fulfillment_status', 20)->default('unfulfilled');
                $table->decimal('estimated_total_amount', 18, 2)->default(0.00);
                $table->string('currency_code', 3)->default('IDR');
                $table->text('notes')->nullable();
                $table->text('rejection_reason')->nullable();
                $table->dateTime('approved_at')->nullable();
                $table->unsignedBigInteger('approved_by_user_id')->nullable();
                $table->softDeletes();
                $table->timestamps();

                $table->unique(['tenant_id', 'id'], 'pr_tenant_id_id_unique');
                $table->unique(['tenant_id', 'pr_number'], 'pr_tenant_pr_number_unique');

                $table->foreign('tenant_id', 'pr_tenant_fk')
                    ->references('id')->on('tenants')
                    ->onDelete('cascade')->onUpdate('cascade');

                $table->foreign(['tenant_id', 'store_id'], 'pr_store_composite_fk')
                    ->references(['tenant_id', 'id'])->on('stores')
                    ->onDelete('cascade')->onUpdate('cascade');

                $table->foreign('requested_by_user_id', 'pr_requested_by_user_fk')
                    ->references('id')->on('users')
                    ->onDelete('restrict')->onUpdate('cascade');

                $table->foreign('approved_by_user_id', 'pr_approved_by_user_fk')
                    ->references('id')->on('users')
                    ->onDelete('set null')->onUpdate('cascade');

                $table->index(['tenant_id', 'store_id', 'status', 'required_by_date'], 'pr_tenant_store_status_date_idx');
                $table->index(['tenant_id', 'requested_by_user_id'], 'pr_tenant_requester_idx');
            });

            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                DB::statement("ALTER TABLE purchase_requests ADD CONSTRAINT chk_pr_status CHECK (status IN ('draft', 'submitted', 'approved', 'rejected', 'closed', 'cancelled'))");
                DB::statement("ALTER TABLE purchase_requests ADD CONSTRAINT chk_pr_fulfillment_status CHECK (fulfillment_status IN ('unfulfilled', 'partially_ordered', 'fully_ordered'))");
                DB::statement('ALTER TABLE purchase_requests ADD CONSTRAINT chk_pr_estimated_amount CHECK (estimated_total_amount >= 0)');
            }
        }

        // 2. Tabel purchase_request_lines
        if (! Schema::hasTable('purchase_request_lines')) {
            Schema::create('purchase_request_lines', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('purchase_request_id');
                $table->string('purchasable_type', 20); // 'product' | 'ingredient'
                $table->unsignedBigInteger('purchasable_id');
                $table->decimal('requested_qty', 18, 4);
                $table->decimal('approved_qty', 18, 4)->default(0.0000);
                $table->decimal('converted_to_po_qty', 18, 4)->default(0.0000);
                $table->string('uom', 32);
                $table->decimal('estimated_unit_price', 18, 4)->default(0.0000);
                $table->decimal('estimated_subtotal', 18, 2)->default(0.00);
                $table->unsignedBigInteger('preferred_supplier_id')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->unique(['tenant_id', 'id'], 'pr_lines_tenant_id_id_unique');

                $table->foreign('tenant_id', 'pr_lines_tenant_fk')
                    ->references('id')->on('tenants')
                    ->onDelete('cascade')->onUpdate('cascade');

                $table->foreign(['tenant_id', 'purchase_request_id'], 'pr_lines_pr_composite_fk')
                    ->references(['tenant_id', 'id'])->on('purchase_requests')
                    ->onDelete('cascade')->onUpdate('cascade');

                $table->foreign('preferred_supplier_id', 'pr_lines_preferred_supplier_fk')
                    ->references('id')->on('suppliers')
                    ->onDelete('set null')->onUpdate('cascade');

                $table->index(['tenant_id', 'purchase_request_id'], 'pr_lines_tenant_pr_idx');
                $table->index(['tenant_id', 'purchasable_type', 'purchasable_id'], 'pr_lines_tenant_item_idx');
            });

            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                DB::statement("ALTER TABLE purchase_request_lines ADD CONSTRAINT chk_pr_lines_purchasable_type CHECK (purchasable_type IN ('product', 'ingredient'))");
                DB::statement('ALTER TABLE purchase_request_lines ADD CONSTRAINT chk_pr_lines_requested_qty CHECK (requested_qty > 0)');
                DB::statement('ALTER TABLE purchase_request_lines ADD CONSTRAINT chk_pr_lines_approved_qty CHECK (approved_qty >= 0)');
                DB::statement('ALTER TABLE purchase_request_lines ADD CONSTRAINT chk_pr_lines_converted_qty CHECK (converted_to_po_qty >= 0 AND converted_to_po_qty <= approved_qty)');
                DB::statement('ALTER TABLE purchase_request_lines ADD CONSTRAINT chk_pr_lines_unit_price CHECK (estimated_unit_price >= 0)');
            }
        }

        // 3. Tabel purchase_approval_rules
        if (! Schema::hasTable('purchase_approval_rules')) {
            Schema::create('purchase_approval_rules', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('store_id')->nullable();
                $table->string('document_type', 32)->default('PR'); // 'PR', 'PO', 'PURCHASE_RETURN', 'INVOICE_VARIANCE', 'GRN_OVER_RECEIPT'
                $table->string('currency_code', 3)->default('IDR');
                $table->decimal('min_amount', 18, 2)->default(0.00);
                $table->decimal('max_amount', 18, 2)->nullable();
                $table->integer('step_order')->default(1);
                $table->unsignedBigInteger('approver_role_id')->nullable();
                $table->string('approver_permission', 100)->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->unique(['tenant_id', 'id'], 'par_tenant_id_id_unique');

                $table->foreign('tenant_id', 'par_tenant_fk')
                    ->references('id')->on('tenants')
                    ->onDelete('cascade')->onUpdate('cascade');

                $table->foreign('store_id', 'par_store_fk')
                    ->references('id')->on('stores')
                    ->onDelete('cascade')->onUpdate('cascade');

                $table->foreign('approver_role_id', 'par_role_fk')
                    ->references('id')->on('roles')
                    ->onDelete('set null')->onUpdate('cascade');

                $table->index(['tenant_id', 'document_type', 'is_active', 'min_amount'], 'par_tenant_doc_active_min_idx');
            });

            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                DB::statement('ALTER TABLE purchase_approval_rules ADD CONSTRAINT chk_par_min_amount CHECK (min_amount >= 0)');
                DB::statement('ALTER TABLE purchase_approval_rules ADD CONSTRAINT chk_par_step_order CHECK (step_order >= 1)');
            }
        }

        // 4. Tabel purchase_approval_requests
        if (! Schema::hasTable('purchase_approval_requests')) {
            Schema::create('purchase_approval_requests', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->string('approvable_type', 100);
                $table->unsignedBigInteger('approvable_id');
                $table->unsignedBigInteger('rule_id')->nullable();
                $table->integer('step_order')->default(1);
                $table->string('status', 20)->default('pending'); // 'pending', 'approved', 'rejected', 'escalated', 'cancelled'
                $table->unsignedBigInteger('submitted_by_user_id');
                $table->unsignedBigInteger('approved_by_user_id')->nullable();
                $table->text('rejection_reason')->nullable();
                $table->dateTime('decided_at')->nullable();
                $table->timestamps();

                $table->unique(['tenant_id', 'id'], 'pareq_tenant_id_id_unique');

                $table->foreign('tenant_id', 'pareq_tenant_fk')
                    ->references('id')->on('tenants')
                    ->onDelete('cascade')->onUpdate('cascade');

                $table->foreign('rule_id', 'pareq_rule_fk')
                    ->references('id')->on('purchase_approval_rules')
                    ->onDelete('set null')->onUpdate('cascade');

                $table->foreign('submitted_by_user_id', 'pareq_submitted_user_fk')
                    ->references('id')->on('users')
                    ->onDelete('restrict')->onUpdate('cascade');

                $table->foreign('approved_by_user_id', 'pareq_approved_user_fk')
                    ->references('id')->on('users')
                    ->onDelete('set null')->onUpdate('cascade');

                $table->index(['tenant_id', 'approvable_type', 'approvable_id', 'status'], 'pareq_tenant_item_status_idx');
            });

            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                DB::statement("ALTER TABLE purchase_approval_requests ADD CONSTRAINT chk_pareq_status CHECK (status IN ('pending', 'approved', 'rejected', 'escalated', 'cancelled'))");
                DB::statement('ALTER TABLE purchase_approval_requests ADD CONSTRAINT chk_pareq_step_order CHECK (step_order >= 1)');
            }
        }

        // 5. Tabel purchase_approval_logs (Append-Only)
        if (! Schema::hasTable('purchase_approval_logs')) {
            Schema::create('purchase_approval_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('approval_request_id');
                $table->string('approvable_type', 100);
                $table->unsignedBigInteger('approvable_id');
                $table->string('action', 32); // 'submitted', 'approved', 'rejected', 'escalated', 'cancelled'
                $table->unsignedBigInteger('actor_id');
                $table->unsignedBigInteger('actor_role_id')->nullable();
                $table->integer('step_order')->default(1);
                $table->text('reason')->nullable();
                $table->json('payload_snapshot')->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->unique(['tenant_id', 'id'], 'palog_tenant_id_id_unique');

                $table->foreign('tenant_id', 'palog_tenant_fk')
                    ->references('id')->on('tenants')
                    ->onDelete('cascade')->onUpdate('cascade');

                $table->foreign('approval_request_id', 'palog_req_fk')
                    ->references('id')->on('purchase_approval_requests')
                    ->onDelete('cascade')->onUpdate('cascade');

                $table->foreign('actor_id', 'palog_actor_fk')
                    ->references('id')->on('users')
                    ->onDelete('restrict')->onUpdate('cascade');

                $table->index(['tenant_id', 'approvable_type', 'approvable_id'], 'palog_tenant_item_idx');
            });

            // Immutability triggers on purchase_approval_logs
            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                DB::unprepared("
                    CREATE TRIGGER trg_purchase_approval_logs_before_update BEFORE UPDATE ON purchase_approval_logs
                    FOR EACH ROW
                    BEGIN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'purchase_approval_logs bersifat append-only: UPDATE dilarang.';
                    END;
                ");

                DB::unprepared("
                    CREATE TRIGGER trg_purchase_approval_logs_before_delete BEFORE DELETE ON purchase_approval_logs
                    FOR EACH ROW
                    BEGIN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'purchase_approval_logs bersifat append-only: DELETE dilarang.';
                    END;
                ");
            }
        }

        // 6. Seed Permissions Modul 5 untuk PR dan Approval Engine
        if (Schema::hasTable('permissions')) {
            $permissions = [
                ['permission_code' => 'purchasing.pr.view', 'description' => 'Melihat daftar dan rincian Permintaan Pembelian (PR)'],
                ['permission_code' => 'purchasing.pr.create', 'description' => 'Membuat draf Permintaan Pembelian (PR) baru'],
                ['permission_code' => 'purchasing.pr.update', 'description' => 'Mengubah draf Permintaan Pembelian (PR)'],
                ['permission_code' => 'purchasing.pr.submit', 'description' => 'Mengajukan PR ke alur persetujuan'],
                ['permission_code' => 'purchasing.pr.approve', 'description' => 'Menyetujui atau menolak Permintaan Pembelian (PR)'],
                ['permission_code' => 'purchasing.pr.cancel', 'description' => 'Membatalkan Permintaan Pembelian (PR)'],
                ['permission_code' => 'purchasing.approval_rules.manage', 'description' => 'Mengonfigurasi aturan ambang batas persetujuan pengadaan'],
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

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::unprepared('DROP TRIGGER IF EXISTS trg_purchase_approval_logs_before_update');
            DB::unprepared('DROP TRIGGER IF EXISTS trg_purchase_approval_logs_before_delete');
        }

        Schema::dropIfExists('purchase_approval_logs');
        Schema::dropIfExists('purchase_approval_requests');
        Schema::dropIfExists('purchase_approval_rules');
        Schema::dropIfExists('purchase_request_lines');
        Schema::dropIfExists('purchase_requests');
    }
};
