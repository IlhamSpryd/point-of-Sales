<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * T2.1: Expand Schema pada tabel tables:
     * - Kolom pendukung rotasi QR: qr_token, qr_token_version, qr_rotated_at
     * - Indeks komposit UNIQUE(tenant_id, store_id, id) sebagai target Composite FK dari orders
     * - Indeks komposit performa query (tenant_id, store_id, is_active, operational_status)
     */
    public function up(): void
    {
        Schema::table('tables', function (Blueprint $table) {
            if (! Schema::hasColumn('tables', 'qr_token')) {
                $table->string('qr_token', 64)->nullable()->after('secure_token');
            }
            if (! Schema::hasColumn('tables', 'qr_token_version')) {
                $table->unsignedInteger('qr_token_version')->default(1)->after('qr_token');
            }
            if (! Schema::hasColumn('tables', 'qr_rotated_at')) {
                $table->timestamp('qr_rotated_at')->nullable()->after('qr_token_version');
            }
        });

        // Backfill qr_token untuk baris eksisting jika masih NULL
        $existingRows = DB::table('tables')->whereNull('qr_token')->get(['id', 'secure_token']);
        foreach ($existingRows as $row) {
            $hexToken = bin2hex(random_bytes(32));
            DB::table('tables')->where('id', $row->id)->update([
                'qr_token' => $hexToken,
                'qr_token_version' => 1,
            ]);
        }

        // Tambahkan unique index komposit (tenant_id, store_id, id) jika belum ada
        $indexes = DB::select(
            'SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
            ['tables', 'tables_tenant_id_store_id_id_unique']
        );
        if (empty($indexes)) {
            Schema::table('tables', function (Blueprint $table) {
                $table->unique(['tenant_id', 'store_id', 'id'], 'tables_tenant_id_store_id_id_unique');
            });
        }

        // Tambahkan unique index pada qr_token
        $qrIndexes = DB::select(
            'SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
            ['tables', 'tables_qr_token_unique']
        );
        if (empty($qrIndexes)) {
            Schema::table('tables', function (Blueprint $table) {
                $table->unique('qr_token', 'tables_qr_token_unique');
            });
        }

        // Tambahkan index query status meja per cabang
        $statusIndexes = DB::select(
            'SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
            ['tables', 'tables_tenant_store_status_active_index']
        );
        if (empty($statusIndexes)) {
            Schema::table('tables', function (Blueprint $table) {
                $table->index(['tenant_id', 'store_id', 'is_active', 'operational_status'], 'tables_tenant_store_status_active_index');
            });
        }
    }

    public function down(): void
    {
        Schema::table('tables', function (Blueprint $table) {
            try {
                $table->dropIndex('tables_tenant_store_status_active_index');
            } catch (Throwable) {
            }
            try {
                $table->dropUnique('tables_qr_token_unique');
            } catch (Throwable) {
            }
            try {
                $table->dropUnique('tables_tenant_id_store_id_id_unique');
            } catch (Throwable) {
            }

            $columnsToDrop = [];
            if (Schema::hasColumn('tables', 'qr_rotated_at')) {
                $columnsToDrop[] = 'qr_rotated_at';
            }
            if (Schema::hasColumn('tables', 'qr_token_version')) {
                $columnsToDrop[] = 'qr_token_version';
            }
            if (Schema::hasColumn('tables', 'qr_token')) {
                $columnsToDrop[] = 'qr_token';
            }
            if (! empty($columnsToDrop)) {
                $table->dropColumn($columnsToDrop);
            }
        });
    }
};
