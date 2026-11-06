<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * TR-006: Transactional WhatsApp Message Outbox (DEC-007).
     */
    public function up(): void
    {
        if (Schema::hasTable('whatsapp_message_outbox')) {
            return;
        }

        Schema::create('whatsapp_message_outbox', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('recipient_phone', 32);
            $table->string('message_type', 32)->default('RECEIPT');
            $table->string('template_id', 64)->nullable();
            $table->json('payload');
            $table->enum('status', ['pending', 'processing', 'sent', 'failed'])->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->string('provider_message_id', 128)->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->index(['tenant_id', 'status', 'created_at'], 'idx_wa_outbox_tenant_status');
            $table->index(['status', 'lease_expires_at'], 'idx_wa_outbox_claim');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('whatsapp_message_outbox');
    }
};

