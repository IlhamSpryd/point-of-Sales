<?php

use App\Support\EmailNormalizer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Tambah kolom identitas, normalisasi email, dan generated key pada tabel users
        if (! Schema::hasColumn('users', 'email_normalized')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('email_normalized', 255)->nullable()->after('email');
            });

            // Backfill email_normalized untuk baris eksisting
            $existingUsers = DB::table('users')->select('id', 'email')->get();
            foreach ($existingUsers as $u) {
                $normalized = EmailNormalizer::normalize($u->email);
                DB::table('users')->where('id', $u->id)->update(['email_normalized' => $normalized]);
            }

            // Ubah menjadi NOT NULL
            DB::statement('ALTER TABLE `users` MODIFY `email_normalized` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL');
        }

        if (! Schema::hasColumn('users', 'email_active_key')) {
            // Virtual generated column: bernilai email_normalized jika baris aktif (deleted_at IS NULL), bernilai NULL jika soft-deleted
            DB::statement('ALTER TABLE `users` ADD COLUMN `email_active_key` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci GENERATED ALWAYS AS (IF(`deleted_at` IS NULL, `email_normalized`, NULL)) VIRTUAL AFTER `email_normalized`');
        }

        if (! Schema::hasIndex('users', 'uq_users_email_active_global')) {
            Schema::table('users', function (Blueprint $table) {
                $table->unique('email_active_key', 'uq_users_email_active_global');
            });
        }

        if (! Schema::hasIndex('users', 'idx_users_email_normalized')) {
            Schema::table('users', function (Blueprint $table) {
                $table->index('email_normalized', 'idx_users_email_normalized');
            });
        }

        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'pending_email')) {
                $table->string('pending_email', 255)->nullable()->after('email_active_key');
            }
            if (! Schema::hasColumn('users', 'pending_email_normalized')) {
                $table->string('pending_email_normalized', 255)->nullable()->after('pending_email');
            }
            if (! Schema::hasColumn('users', 'pending_email_requested_at')) {
                $table->timestamp('pending_email_requested_at')->nullable()->after('pending_email_normalized');
            }
            if (! Schema::hasColumn('users', 'password_changed_at')) {
                $table->timestamp('password_changed_at')->nullable()->after('pending_email_requested_at');
            }
        });

        // 2. Buat tabel email_verification_otps
        if (! Schema::hasTable('email_verification_otps')) {
            Schema::create('email_verification_otps', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('user_id');
                $table->string('email', 255);
                $table->string('email_normalized', 255);
                $table->string('otp_code_hash', 64);
                $table->string('action', 32)->default('registration');
                $table->unsignedSmallInteger('attempts')->default(0);
                $table->unsignedSmallInteger('max_attempts')->default(5);
                $table->timestamp('expires_at');
                $table->timestamp('consumed_at')->nullable();
                $table->timestamp('superseded_at')->nullable();
                $table->timestamp('locked_at')->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->text('user_agent')->nullable();
                $table->timestamps();

                $table->foreign(['tenant_id', 'user_id'], 'fk_email_otp_tenant_user')
                    ->references(['tenant_id', 'id'])
                    ->on('users')
                    ->cascadeOnDelete();

                $table->index(
                    ['tenant_id', 'user_id', 'action', 'consumed_at', 'superseded_at', 'expires_at'],
                    'idx_otp_user_active'
                );
                $table->index('email_normalized', 'idx_otp_email_norm');
                $table->index('expires_at', 'idx_otp_expires_at');
            });
        }

        // 3. Buat tabel user_oauth_identities
        if (! Schema::hasTable('user_oauth_identities')) {
            Schema::create('user_oauth_identities', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('user_id');
                $table->string('provider', 32);
                $table->string('provider_subject', 255);
                $table->string('provider_email', 255)->nullable();
                $table->string('provider_email_normalized', 255)->nullable();
                $table->boolean('provider_email_verified')->default(false);
                $table->boolean('is_private_relay')->default(false);
                $table->timestamp('linked_at');
                $table->timestamp('last_used_at')->nullable();
                $table->timestamps();

                $table->unique(['provider', 'provider_subject'], 'uq_oauth_provider_subject');
                $table->unique(['user_id', 'provider'], 'uq_oauth_user_provider');

                $table->foreign(['tenant_id', 'user_id'], 'fk_oauth_tenant_user')
                    ->references(['tenant_id', 'id'])
                    ->on('users')
                    ->cascadeOnDelete();

                $table->index('provider_email_normalized', 'idx_oauth_email_norm');
            });
        }

        // 4. Buat tabel user_invitations
        if (! Schema::hasTable('user_invitations')) {
            Schema::create('user_invitations', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('role_id');
                $table->unsignedBigInteger('invited_by_user_id');
                $table->string('email', 255);
                $table->string('email_normalized', 255);
                $table->string('name', 255);
                $table->string('token_hash', 64)->unique('uq_invitation_token_hash');
                $table->string('status', 30)->default('pending');
                $table->timestamp('expires_at');
                $table->timestamp('accepted_at')->nullable();
                $table->timestamp('revoked_at')->nullable();
                $table->timestamp('superseded_at')->nullable();
                $table->timestamps();

                $table->foreign('tenant_id', 'fk_invitations_tenant')
                    ->references('id')
                    ->on('tenants')
                    ->cascadeOnDelete();

                $table->foreign(['tenant_id', 'role_id'], 'fk_invitations_role')
                    ->references(['tenant_id', 'id'])
                    ->on('roles')
                    ->cascadeOnDelete();

                $table->foreign(['tenant_id', 'invited_by_user_id'], 'fk_invitations_inviter')
                    ->references(['tenant_id', 'id'])
                    ->on('users')
                    ->cascadeOnDelete();

                $table->index(['tenant_id', 'status'], 'idx_invitation_tenant_status');
                $table->index('email_normalized', 'idx_invitation_email_norm');
            });
        }

        // 5. Buat tabel user_invitation_stores
        if (! Schema::hasTable('user_invitation_stores')) {
            Schema::create('user_invitation_stores', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('user_invitation_id');
                $table->unsignedBigInteger('store_id');
                $table->timestamps();

                $table->unique(['user_invitation_id', 'store_id'], 'uq_invitation_store');

                $table->foreign('user_invitation_id', 'fk_inv_store_invitation')
                    ->references('id')
                    ->on('user_invitations')
                    ->cascadeOnDelete();

                $table->foreign(['tenant_id', 'store_id'], 'fk_inv_store_store')
                    ->references(['tenant_id', 'id'])
                    ->on('stores')
                    ->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        // Drop tabel dengan urutan terbalik dari foreign key dependency
        Schema::dropIfExists('user_invitation_stores');
        Schema::dropIfExists('user_invitations');
        Schema::dropIfExists('user_oauth_identities');
        Schema::dropIfExists('email_verification_otps');

        // Drop indeks dan kolom pada tabel users
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasIndex('users', 'uq_users_email_active_global')) {
                $table->dropUnique('uq_users_email_active_global');
            }
            if (Schema::hasIndex('users', 'idx_users_email_normalized')) {
                $table->dropIndex('idx_users_email_normalized');
            }

            $colsToDrop = [
                'password_changed_at',
                'pending_email_requested_at',
                'pending_email_normalized',
                'pending_email',
                'email_active_key',
                'email_normalized',
            ];

            foreach ($colsToDrop as $col) {
                if (Schema::hasColumn('users', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
