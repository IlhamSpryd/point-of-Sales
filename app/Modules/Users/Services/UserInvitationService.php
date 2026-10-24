<?php

namespace App\Modules\Users\Services;

use App\Models\EmailOutbox;
use App\Models\Role;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserInvitation;
use App\Models\UserInvitationStore;
use App\Models\UserOAuthIdentity;
use App\Services\EmployeeIdGenerator;
use App\Services\EntitlementService;
use App\Support\EmailNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class UserInvitationService
{
    public function __construct(
        protected ?EntitlementService $entitlementService = null
    ) {
        $this->entitlementService = $this->entitlementService ?? app(EntitlementService::class);
    }

    /**
     * Menerbitkan undangan karyawan baru dengan Privacy-Preserving Cross-Tenant Guard [4B].
     *
     * @param  array{name: string, email: string, role_id: int, store_ids: array<int>}  $data
     */
    public function issueInvitation(User $inviter, array $data): UserInvitation
    {
        $tenantId = (int) $inviter->tenant_id;
        $this->entitlementService->assertCanInviteEmployee($tenantId);

        $name = trim($data['name']);
        $email = trim($data['email']);
        $emailNormalized = EmailNormalizer::normalize($email);
        $roleId = (int) $data['role_id'];
        $storeIds = array_map('intval', $data['store_ids']);

        // 1. Validasi Keberadaan dan Kepemilikan Role (bukan Owner)
        $role = Role::withoutTenantScope()
            ->where('id', $roleId)
            ->where('tenant_id', $tenantId)
            ->first();

        if (! $role || strtolower($role->name) === 'owner') {
            throw ValidationException::withMessages([
                'role_id' => 'Peran tidak valid atau tidak diizinkan untuk undangan karyawan.',
            ]);
        }

        // 2. Validasi Kepemilikan Store
        if (empty($storeIds)) {
            throw ValidationException::withMessages([
                'store_ids' => 'Pilih minimal satu cabang penugasan.',
            ]);
        }

        $validStoresCount = Store::withoutTenantScope()
            ->whereIn('id', $storeIds)
            ->where('tenant_id', $tenantId)
            ->count();

        if ($validStoresCount !== count($storeIds)) {
            throw ValidationException::withMessages([
                'store_ids' => 'Satu atau lebih cabang yang dipilih tidak valid untuk bisnis Anda.',
            ]);
        }

        // 3. Privacy-Preserving Cross-Tenant Guard [4B]
        $existingActiveUser = User::withoutGlobalScopes()
            ->where('email_normalized', $emailNormalized)
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->first();

        if ($existingActiveUser !== null) {
            // Jika akun aktif di tenant YANG SAMA: beri tahu pengundang secara eksplisit
            if ((int) $existingActiveUser->tenant_id === $tenantId) {
                throw ValidationException::withMessages([
                    'email' => 'Pengguna dengan email ini sudah terdaftar dan aktif di bisnis Anda.',
                ]);
            }

            // Jika akun aktif di tenant LAIN: ANTI-ENUMERATION
            // JANGAN bocorkan ke pengundang bahwa email terdaftar di tenant lain.
            // Catat status internal 'blocked_cross_tenant' dan kirim notifikasi keamanan ke pemilik email.
            return DB::transaction(function () use ($tenantId, $inviter, $roleId, $name, $email, $emailNormalized, $storeIds) {
                $plaintextToken = Str::random(64);
                $tokenHash = hash('sha256', $plaintextToken);

                $invitation = UserInvitation::create([
                    'tenant_id' => $tenantId,
                    'role_id' => $roleId,
                    'invited_by_user_id' => $inviter->id,
                    'name' => $name,
                    'email' => $email,
                    'email_normalized' => $emailNormalized,
                    'token_hash' => $tokenHash,
                    'status' => UserInvitation::STATUS_BLOCKED_CROSS_TENANT,
                    'expires_at' => now()->addHours(72),
                ]);

                $invitation->plaintextToken = $plaintextToken;

                foreach ($storeIds as $storeId) {
                    UserInvitationStore::create([
                        'tenant_id' => $tenantId,
                        'user_invitation_id' => $invitation->id,
                        'store_id' => $storeId,
                    ]);
                }

                EmailOutbox::create([
                    'tenant_id' => $tenantId,
                    'user_id' => null,
                    'recipient_email' => $email,
                    'subject' => 'Pemberitahuan Keamanan: Percobaan Undangan ke Organisasi Baru',
                    'event_type' => 'cross_tenant_invitation_blocked',
                    'body_text' => "Halo {$name},\n\nEmail Anda saat ini aktif pada organisasi lain di Apeiron POS. Undangan staf dari organisasi lain tidak dapat diproses secara otomatis demi privasi dan keamanan data Anda.",
                    'status' => 'pending',
                ]);

                return $invitation;
            });
        }

        // 4. Alur Standar: Buat token 72 jam dan gantikan undangan pending lama
        return DB::transaction(function () use ($tenantId, $inviter, $roleId, $name, $email, $emailNormalized, $storeIds) {
            // Gantikan undangan pending lama jika ada
            UserInvitation::where('tenant_id', $tenantId)
                ->where('email_normalized', $emailNormalized)
                ->where('status', UserInvitation::STATUS_PENDING)
                ->update([
                    'status' => UserInvitation::STATUS_SUPERSEDED,
                    'superseded_at' => now(),
                ]);

            $plaintextToken = Str::random(64);
            $tokenHash = hash('sha256', $plaintextToken);

            $invitation = UserInvitation::create([
                'tenant_id' => $tenantId,
                'role_id' => $roleId,
                'invited_by_user_id' => $inviter->id,
                'name' => $name,
                'email' => $email,
                'email_normalized' => $emailNormalized,
                'token_hash' => $tokenHash,
                'status' => UserInvitation::STATUS_PENDING,
                'expires_at' => now()->addHours(72),
            ]);

            $invitation->plaintextToken = $plaintextToken;

            foreach ($storeIds as $storeId) {
                UserInvitationStore::create([
                    'tenant_id' => $tenantId,
                    'user_invitation_id' => $invitation->id,
                    'store_id' => $storeId,
                ]);
            }

            $invitationUrl = route('invitations.accept', ['token' => $plaintextToken]);

            $tenantName = $inviter->tenant ? $inviter->tenant->name : 'Apeiron POS';

            EmailOutbox::create([
                'tenant_id' => $tenantId,
                'user_id' => null,
                'recipient_email' => $email,
                'subject' => 'Undangan Bergabung ke '.$tenantName,
                'event_type' => 'employee_invitation',
                'body_text' => "Halo {$name},\n\nAnda diundang untuk bergabung ke {$tenantName}.\nSilakan klik tautan berikut untuk mengaktifkan akun Anda:\n{$invitationUrl}\n\nTautan berlaku hingga: ".$invitation->expires_at->toIso8601String(),
                'status' => 'pending',
            ]);

            return $invitation;
        });
    }

    /**
     * Mengirim ulang undangan staf (membatalkan token lama dan membuat token 72 jam baru).
     */
    public function resendInvitation(User $inviter, int $invitationId): UserInvitation
    {
        $tenantId = (int) $inviter->tenant_id;

        $oldInvitation = UserInvitation::where('id', $invitationId)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        $storeIds = UserInvitationStore::where('user_invitation_id', $oldInvitation->id)
            ->pluck('store_id')
            ->all();

        return $this->issueInvitation($inviter, [
            'name' => $oldInvitation->name,
            'email' => $oldInvitation->email,
            'role_id' => $oldInvitation->role_id,
            'store_ids' => $storeIds,
        ]);
    }

    /**
     * Mencabut undangan yang masih pending.
     */
    public function revokeInvitation(User $inviter, int $invitationId): UserInvitation
    {
        $tenantId = (int) $inviter->tenant_id;

        $invitation = UserInvitation::where('id', $invitationId)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        if ($invitation->status !== UserInvitation::STATUS_PENDING) {
            throw new \DomainException('Hanya undangan berstatus pending yang dapat dicabut.');
        }

        $invitation->update([
            'status' => UserInvitation::STATUS_REVOKED,
            'revoked_at' => now(),
        ]);

        return $invitation;
    }

    /**
     * Menerima dan mengaktifkan akun staf dari token undangan.
     * Mengabaikan seluruh parameter privilege dari klien (Anti-Tampering).
     *
     * @param  array{password: string, password_confirmation?: string}  $data
     */
    public function acceptInvitation(string $plaintextToken, array $data): User
    {
        return DB::transaction(function () use ($plaintextToken, $data) {
            $tokenHash = hash('sha256', $plaintextToken);

            /** @var UserInvitation|null $invitation */
            $invitation = UserInvitation::where('token_hash', $tokenHash)
                ->lockForUpdate()
                ->first();

            if (! $invitation) {
                abort(404, 'Token undangan tidak valid.');
            }

            // Validasi status siklus hidup token
            if ($invitation->status === UserInvitation::STATUS_BLOCKED_CROSS_TENANT) {
                abort(409, 'Akun dengan email ini sedang aktif pada organisasi lain.');
            }

            if ($invitation->status === UserInvitation::STATUS_SUPERSEDED || $invitation->superseded_at !== null) {
                abort(410, 'Undangan ini telah digantikan oleh undangan baru.');
            }

            if ($invitation->status === UserInvitation::STATUS_REVOKED || $invitation->revoked_at !== null) {
                abort(410, 'Undangan ini telah dicabut oleh pemilik bisnis.');
            }

            if ($invitation->status === UserInvitation::STATUS_ACCEPTED || $invitation->accepted_at !== null) {
                abort(410, 'Undangan ini sudah pernah digunakan.');
            }

            if ($invitation->expires_at->isPast()) {
                abort(410, 'Undangan ini telah kedaluwarsa.');
            }

            if ($invitation->status !== UserInvitation::STATUS_PENDING) {
                abort(410, 'Undangan tidak valid.');
            }

            // Cross-Tenant Lockout Check saat aktivasi:
            // Jika ada user aktif dengan email ini di tenant mana pun, tolak dengan 409
            $activeUser = User::withoutGlobalScopes()
                ->where('email_normalized', $invitation->email_normalized)
                ->whereNull('deleted_at')
                ->where('is_active', true)
                ->lockForUpdate()
                ->first();

            if ($activeUser !== null) {
                abort(409, 'Akun dengan email ini sedang aktif pada organisasi lain.');
            }

            // Anti-tampering: Hanya gunakan data dari $invitation di database, abaikan input klien selain password!
            $password = (string) $data['password'];
            if (strlen($password) < 8) {
                throw ValidationException::withMessages([
                    'password' => 'Kata sandi minimal 8 karakter.',
                ]);
            }

            // Mitigasi Race Condition Sprint 3.3:
            // Kunci baris tenants induk sebelum inisialisasi alokasi employee_id
            Tenant::where('id', $invitation->tenant_id)->lockForUpdate()->first();

            $employeeId = EmployeeIdGenerator::next($invitation->tenant_id);

            $user = new User;
            $user->tenant_id = $invitation->tenant_id;
            $user->role_id = $invitation->role_id;
            $user->name = $invitation->name;
            $user->email = $invitation->email;
            $user->email_normalized = $invitation->email_normalized;
            $user->employee_id = $employeeId;
            $user->password_hash = bcrypt($password);
            $user->pin_hash = null; // Deferred Cashier PIN
            $user->is_active = true;
            $user->email_verified_at = now();
            $user->save();

            // Salin relasi cabang dari user_invitation_stores ke user_stores
            $storeIds = UserInvitationStore::where('user_invitation_id', $invitation->id)
                ->pluck('store_id')
                ->all();

            foreach ($storeIds as $storeId) {
                DB::table('user_stores')->insertOrIgnore([
                    'tenant_id' => $invitation->tenant_id,
                    'user_id' => $user->id,
                    'store_id' => $storeId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // Tandai undangan sebagai accepted
            $invitation->status = UserInvitation::STATUS_ACCEPTED;
            $invitation->accepted_at = now();
            $invitation->save();

            return $user;
        });
    }

    /**
     * Aktivasi undangan via OAuth (Google/Apple) dengan intent 'accept_invitation'.
     */
    public function acceptInvitationViaOAuth(string $plaintextToken, string $provider, array $claims): User
    {
        return DB::transaction(function () use ($plaintextToken, $provider, $claims) {
            $tokenHash = hash('sha256', $plaintextToken);

            /** @var UserInvitation|null $invitation */
            $invitation = UserInvitation::where('token_hash', $tokenHash)
                ->lockForUpdate()
                ->first();

            if (! $invitation) {
                abort(404, 'Token undangan tidak valid.');
            }

            if (! $invitation->isPending()) {
                abort(410, 'Undangan ini tidak valid atau telah kedaluwarsa.');
            }

            $claimsEmailNormalized = EmailNormalizer::normalize($claims['email'] ?? '');
            if ($claimsEmailNormalized !== $invitation->email_normalized) {
                abort(422, 'Email akun Google/Apple Anda tidak cocok dengan email tujuan undangan.');
            }

            $activeUser = User::withoutGlobalScopes()
                ->where('email_normalized', $invitation->email_normalized)
                ->whereNull('deleted_at')
                ->where('is_active', true)
                ->lockForUpdate()
                ->first();

            if ($activeUser !== null) {
                abort(409, 'Akun dengan email ini sedang aktif pada organisasi lain.');
            }

            Tenant::where('id', $invitation->tenant_id)->lockForUpdate()->first();
            $employeeId = EmployeeIdGenerator::next($invitation->tenant_id);

            $user = new User;
            $user->tenant_id = $invitation->tenant_id;
            $user->role_id = $invitation->role_id;
            $user->name = ! empty($claims['name']) ? $claims['name'] : $invitation->name;
            $user->email = $invitation->email;
            $user->email_normalized = $invitation->email_normalized;
            $user->employee_id = $employeeId;
            $user->password_hash = null;
            $user->pin_hash = null;
            $user->is_active = true;
            $user->email_verified_at = now();
            $user->save();

            $storeIds = UserInvitationStore::where('user_invitation_id', $invitation->id)
                ->pluck('store_id')
                ->all();

            foreach ($storeIds as $storeId) {
                DB::table('user_stores')->insertOrIgnore([
                    'tenant_id' => $invitation->tenant_id,
                    'user_id' => $user->id,
                    'store_id' => $storeId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // Tautkan identitas OAuth
            UserOAuthIdentity::create([
                'tenant_id' => $invitation->tenant_id,
                'user_id' => $user->id,
                'provider' => $provider,
                'provider_subject' => $claims['sub'],
                'provider_email' => $claims['email'] ?? $invitation->email,
                'provider_email_normalized' => $claimsEmailNormalized,
                'provider_email_verified' => (bool) ($claims['email_verified'] ?? true),
                'is_private_relay' => (bool) ($claims['is_private_email'] ?? false),
                'linked_at' => now(),
            ]);

            $invitation->status = UserInvitation::STATUS_ACCEPTED;
            $invitation->accepted_at = now();
            $invitation->save();

            return $user;
        });
    }
}
