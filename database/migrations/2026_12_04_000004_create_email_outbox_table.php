<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('email_outbox')) return;
        Schema::create('email_outbox', function (Blueprint $table) {
            $table->id();
            $table->string('event_type', 50);
            $table->string('recipient_email', 255);
            $table->string('subject', 255);
            $table->text('body_text');
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'next_attempt_at']);
            $table->index(['tenant_id', 'user_id']);
        });
    }
    public function down(): void { Schema::dropIfExists('email_outbox'); }
};
