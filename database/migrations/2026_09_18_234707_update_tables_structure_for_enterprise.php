<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('tables', function (Blueprint $table) {
            // Tambahkan Business ID jika belum ada
            if (!Schema::hasColumn('tables', 'table_code')) {
                $table->string('table_code')->unique()->nullable()->after('id');
            }
            // Ubah table_number jadi table_name agar lebih fleksibel
            if (Schema::hasColumn('tables', 'table_number') && !Schema::hasColumn('tables', 'table_name')) {
                $table->renameColumn('table_number', 'table_name');
            }
            
            // Tambahkan kolom operasional baru
            $table->integer('capacity')->default(2)->after('table_name');
            $table->string('area')->default('Indoor')->after('capacity');
            
            // Hapus status lama (string active/inactive) ganti ke operational_status & is_active
            if (Schema::hasColumn('tables', 'status')) {
                $table->dropColumn('status');
            }
            
            $table->boolean('is_active')->default(true)->after('area');
            $table->enum('operational_status', ['available', 'occupied', 'cleaning', 'reserved'])
                  ->default('available')->after('is_active');
                  
            $table->softDeletes();
        });
    }

    public function down(): void {
        Schema::table('tables', function (Blueprint $table) {
            $table->dropColumn(['table_code', 'capacity', 'area', 'is_active', 'operational_status', 'deleted_at']);
            $table->string('status')->default('active');
            if (Schema::hasColumn('tables', 'table_name')) {
                $table->renameColumn('table_name', 'table_number');
            }
        });
    }
};
