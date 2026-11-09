<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tax_submissions')) {
            Schema::create('tax_submissions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('company_id')->nullable();
                $table->string('provider_name', 50); // internal, coretax_pjap, manual
                $table->string('idempotency_key', 150);
                $table->string('submission_reference', 100);
                $table->unsignedBigInteger('invoice_id')->nullable();
                $table->string('invoice_number', 100);
                $table->string('tax_invoice_serial_number', 50)->nullable();
                $table->string('status', 30)->default('PENDING'); // PENDING, SUCCESS, REJECTED, CANCELLED, INTERNAL_RECORDED
                $table->string('raw_payload_hash', 64);
                $table->longText('request_payload_masked')->nullable();
                $table->longText('response_payload_masked')->nullable();
                $table->unsignedInteger('retry_count')->default(0);
                $table->text('last_error')->nullable();
                $table->timestamp('submitted_at')->nullable();
                $table->timestamps();

                $table->unique(['tenant_id', 'id'], 'uq_tax_submissions_tenant_id');
                $table->unique(['tenant_id', 'idempotency_key'], 'uq_tax_sub_tenant_idempotency');
                $table->unique(['tenant_id', 'submission_reference'], 'uq_tax_sub_tenant_ref');
                $table->index(['tenant_id', 'status', 'provider_name'], 'idx_tax_sub_tenant_status_prov');
                $table->index(['tenant_id', 'company_id'], 'idx_tax_sub_tenant_company');

                $table->foreign('tenant_id')
                    ->references('id')->on('tenants')
                    ->onDelete('cascade');

                if (Schema::hasTable('companies')) {
                    $table->foreign(['tenant_id', 'company_id'])
                        ->references(['tenant_id', 'id'])->on('companies')
                        ->onDelete('restrict')->onUpdate('cascade');
                }
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_submissions');
    }
};
