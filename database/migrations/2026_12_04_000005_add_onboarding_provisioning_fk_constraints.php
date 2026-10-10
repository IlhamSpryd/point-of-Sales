<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Domain 1 — FK constraints untuk memastikan hasil ledger/token/outbox tidak
 * menunjuk ke tenant/user yang tidak ada. CASCADE dipilih karena:
 *  - provisioning_requests hanya menyimpan referensi hasil, ledger audit
 *    tetap ada saat tenant dihapus? Untuk historis, tenant deletion RESTRICT
 *    existing; tetap menggunakan SET NULL jika tenant hard-delete di masa
 *    depan, tetapi hasil ledger harus dapat direkonsiliasi. Di sini tenant
 *    soft-delete saja sehingga FK CASCADE tidak akan terpicu normal.
 *  - onboarding token/outbox tidak boleh hidup setelah tenant/user hard-delete.
 *  - nonce tetap tanpa FK (transport record lintas domain).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('provisioning_requests', function (Blueprint $table) {
            $table->foreign('result_tenant_id', 'provisioning_requests_result_tenant_fk')
                ->references('id')->on('tenants')->nullOnDelete();
            $table->foreign('result_user_id', 'provisioning_requests_result_user_fk')
                ->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('owner_onboarding_tokens', function (Blueprint $table) {
            $table->foreign(['tenant_id', 'user_id'], 'onboarding_tokens_tenant_user_fk')
                ->references(['tenant_id', 'id'])->on('users')->cascadeOnDelete();
        });

        Schema::table('email_outbox', function (Blueprint $table) {
            // Outbox tetap disimpan sebagai riwayat pengiriman meskipun user
            // hard-delete; tenant_id/user_id metadata nullable tanpa cascade.
            $table->foreign('tenant_id', 'email_outbox_tenant_fk')
                ->references('id')->on('tenants')->nullOnDelete();
            $table->foreign('user_id', 'email_outbox_user_fk')
                ->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('email_outbox', function (Blueprint $table) {
            $table->dropForeign('email_outbox_tenant_fk');
            $table->dropForeign('email_outbox_user_fk');
        });

        Schema::table('owner_onboarding_tokens', function (Blueprint $table) {
            $table->dropForeign('onboarding_tokens_tenant_user_fk');
        });

        Schema::table('provisioning_requests', function (Blueprint $table) {
            $table->dropForeign('provisioning_requests_result_tenant_fk');
            $table->dropForeign('provisioning_requests_result_user_fk');
        });
    }
};
