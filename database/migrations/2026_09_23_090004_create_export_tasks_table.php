<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// [OMEGA-NODE5] Ekspor laporan asinkron -- generik lintas jenis ekspor via kolom `type` | 2026-09-23
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('export_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 50); // sales_report, monthly_export, dst
            $table->json('parameters')->nullable();
            $table->string('status', 20)->default('pending');
            $table->string('file_path')->nullable();
            $table->unsignedBigInteger('file_size_bytes')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['requested_by', 'status'], 'export_tasks_requester_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('export_tasks');
    }
};
