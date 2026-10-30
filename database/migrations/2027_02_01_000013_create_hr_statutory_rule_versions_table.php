<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('statutory_rule_versions')) {
            Schema::create('statutory_rule_versions', function (Blueprint $table) {
                $table->id();
                $table->string('rule_category', 50); // PPH21_TER, PPH21_PASAL17, BPJS_TK, BPJS_KS, PTKP_VALUES, PPH21_DTP
                $table->string('jurisdiction', 10)->default('ID');
                $table->string('version_code', 50);
                $table->date('effective_from');
                $table->date('effective_to')->nullable();
                $table->string('legal_reference', 255);
                $table->json('rule_parameters');
                $table->boolean('is_locked')->default(false);
                $table->timestamps();

                $table->index(['jurisdiction', 'rule_category', 'effective_from'], 'idx_statutory_resolution');
                $table->unique(['jurisdiction', 'rule_category', 'version_code'], 'uniq_statutory_rule_ver');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('statutory_rule_versions');
    }
};
