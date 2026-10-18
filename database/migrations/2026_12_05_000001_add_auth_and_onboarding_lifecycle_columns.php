<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Tambah kolom lifecycle, onboarding, status, dan slug pada tabel tenants
        Schema::table('tenants', function (Blueprint $table) {
            if (! Schema::hasColumn('tenants', 'slug')) {
                $table->string('slug', 255)->nullable()->after('name');
            }
            if (! Schema::hasColumn('tenants', 'status')) {
                $table->string('status', 30)->default('active')->after('slug');
            }
            if (! Schema::hasColumn('tenants', 'onboarding_status')) {
                $table->string('onboarding_status', 40)->default('pending_verification')->after('status');
            }
            if (! Schema::hasColumn('tenants', 'onboarding_completed_at')) {
                $table->timestamp('onboarding_completed_at')->nullable()->after('onboarding_status');
            }
            if (! Schema::hasColumn('tenants', 'access_status')) {
                $table->string('access_status', 30)->default('provisioning')->after('onboarding_completed_at');
            }
            if (! Schema::hasColumn('tenants', 'subscription_status')) {
                $table->string('subscription_status', 30)->default('trialing')->after('access_status');
            }
            if (! Schema::hasColumn('tenants', 'subscription_plan')) {
                $table->string('subscription_plan', 50)->default('standard_trial')->after('subscription_status');
            }
            if (! Schema::hasColumn('tenants', 'country_code')) {
                $table->string('country_code', 2)->default('ID')->after('subscription_plan');
            }
        });

        // Tambah indeks onboarding_status bila belum ada
        if (! Schema::hasIndex('tenants', 'tenants_onboarding_status_index')) {
            Schema::table('tenants', function (Blueprint $table) {
                $table->index('onboarding_status', 'tenants_onboarding_status_index');
            });
        }

        // 2. Tambah kolom operasional cabang pada tabel stores
        Schema::table('stores', function (Blueprint $table) {
            if (! Schema::hasColumn('stores', 'code')) {
                $table->string('code', 20)->default('MAIN-01')->after('name');
            }
            if (! Schema::hasColumn('stores', 'timezone')) {
                $table->string('timezone', 64)->default('Asia/Jakarta')->after('code');
            }
            if (! Schema::hasColumn('stores', 'business_date_cutoff')) {
                $table->string('business_date_cutoff', 5)->default('00:00')->after('timezone');
            }
            if (! Schema::hasColumn('stores', 'default_channel')) {
                $table->string('default_channel', 30)->default('dine_in')->after('business_date_cutoff');
            }
            if (! Schema::hasColumn('stores', 'phone')) {
                $table->string('phone', 30)->nullable()->after('default_channel');
            }
            if (! Schema::hasColumn('stores', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('phone');
            }
            if (! Schema::hasColumn('stores', 'is_initial')) {
                $table->boolean('is_initial')->default(false)->after('is_active');
            }
        });

        // 3. Tambah kolom rate limiting & lockout pada tabel users
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'failed_login_attempts')) {
                $table->unsignedSmallInteger('failed_login_attempts')->default(0)->after('is_active');
            }
            if (! Schema::hasColumn('users', 'locked_until')) {
                $table->timestamp('locked_until')->nullable()->after('failed_login_attempts');
            }
        });

        // 4. Backfill aman & idempoten untuk tenant existing:
        // Tenant yang sudah memiliki owner terverifikasi atau sudah ada sebelum migrasi ini
        // ditandai onboarding_status = 'completed' dan access_status = 'active'
        DB::table('tenants')
            ->whereNull('onboarding_status')
            ->orWhere('onboarding_status', 'pending_verification')
            ->whereExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('users')
                    ->whereColumn('users.tenant_id', 'tenants.id')
                    ->whereNotNull('users.email_verified_at');
            })
            ->update([
                'onboarding_status' => 'completed',
                'onboarding_completed_at' => now(),
                'access_status' => 'active',
                'subscription_status' => 'active',
                'status' => 'active',
            ]);

        // Berikan slug default bila belum ada
        $tenantsWithoutSlug = DB::table('tenants')->whereNull('slug')->get();
        foreach ($tenantsWithoutSlug as $t) {
            $baseSlug = Str::slug($t->name ?: 'tenant-'.$t->id);
            $slug = $baseSlug ?: 'tenant-'.$t->id;
            DB::table('tenants')->where('id', $t->id)->update(['slug' => $slug]);
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'locked_until')) {
                $table->dropColumn('locked_until');
            }
            if (Schema::hasColumn('users', 'failed_login_attempts')) {
                $table->dropColumn('failed_login_attempts');
            }
        });

        Schema::table('stores', function (Blueprint $table) {
            $cols = ['is_initial', 'is_active', 'phone', 'default_channel', 'business_date_cutoff', 'timezone', 'code'];
            foreach ($cols as $col) {
                if (Schema::hasColumn('stores', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        Schema::table('tenants', function (Blueprint $table) {
            $cols = [
                'country_code',
                'subscription_plan',
                'subscription_status',
                'access_status',
                'onboarding_completed_at',
                'onboarding_status',
                'status',
                'slug',
            ];
            foreach ($cols as $col) {
                if (Schema::hasColumn('tenants', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
