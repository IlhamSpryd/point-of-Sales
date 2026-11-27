<?php

namespace App\Http\Controllers\Auth;

use App\Core\Context\TenantContext;
use App\Http\Controllers\Controller;
use App\Modules\Organization\Models\EmailOutbox;
use App\Modules\Organization\Models\Role;
use App\Modules\Organization\Models\Store;
use App\Modules\Organization\Models\Tenant;
use App\Modules\Organization\Models\User;
use App\Modules\Organization\Services\EmailVerificationOtpService;
use App\Modules\Subscription\Models\Plan;
use App\Modules\Subscription\Services\SubscriptionLifecycleService;
use App\Platform\Database\DatabaseErrorClassifier;
use App\Support\EmailNormalizer;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules;
use Illuminate\View\View;

class RegisteredUserController extends Controller
{
    /**
     * Tampilkan form registrasi Owner baru jika fitur registrasi publik aktif.
     */
    public function create(): View|RedirectResponse
    {
        if (! config('pos.allow_web_registration', false)) {
            return redirect()->route('login')
                ->with('info', 'New business registration is processed through the official marketing portal.');
        }

        return view('auth.register');
    }

    /**
     * Memproses pendaftaran Owner mandiri baru dengan mitigasi balapan konkurensi InnoDB,
     * perlindungan anti-enumerasi, serta penerbitan tantangan OTP 6 digit.
     */
    public function store(Request $request): RedirectResponse
    {
        if (! config('pos.allow_web_registration', false)) {
            abort(405, 'Direct public registration is disabled on this Point of Sale terminal.');
        }

        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'business_name' => ['nullable', 'string', 'max:255'],
            'primary_industry' => ['nullable', 'string', 'in:fnb,retail,hybrid'],
            'business_subtype' => ['nullable', 'string', 'max:50'],
        ]);

        $normalizedEmail = EmailNormalizer::normalize($request->string('email'));
        $lockKey = 'lock:identity:email:'.hash('sha256', $normalizedEmail);
        $lock = Cache::lock($lockKey, 10);

        if (! $lock->get()) {
            return redirect()->route('login')
                ->with('status', 'Pendaftaran sedang diproses. Silakan periksa email Anda.');
        }

        try {
            return DB::transaction(function () use ($request, $normalizedEmail) {
                // Periksa apakah user aktif sudah terdaftar secara global
                // @tenant-bypass: auth_lookup
                $existingUser = User::withoutGlobalScopes()
                    ->where('email_normalized', $normalizedEmail)
                    ->whereNull('deleted_at')
                    ->first();

                if ($existingUser !== null) {
                    // Respons Anti-Enumerasi [REQ-SEC-23]:
                    // Jangan bocorkan keberadaan akun melalui kode 422 maupun pesan error.
                    // Kirimkan notifikasi keamanan ke email pemilik sah via EmailOutbox.
                    EmailOutbox::create([
                        'event_type' => 'duplicate_registration_attempt',
                        'recipient_email' => $normalizedEmail,
                        'subject' => 'Percobaan Pendaftaran Akun Apeiron POS',
                        'body_text' => "Halo {$existingUser->name},\n\nSeseorang mencoba mendaftarkan akun bisnis baru menggunakan alamat email ini di Apeiron POS.\n\nJika Anda yang melakukan percobaan ini, silakan masuk ke akun Anda atau gunakan fitur lupa kata sandi jika Anda tidak dapat mengakses akun Anda.\n\nSalam,\nTim Apeiron POS",
                        'tenant_id' => $existingUser->tenant_id,
                        'user_id' => $existingUser->id,
                        'status' => EmailOutbox::STATUS_PENDING,
                        'attempts' => 0,
                    ]);

                    return redirect()->route('login')
                        ->with('status', 'Pendaftaran berhasil diproses. Silakan periksa email Anda untuk instruksi verifikasi.');
                }

                // Buat Tenant baru (status belum onboarded)
                $businessName = trim((string) $request->input('business_name', ''));
                if ($businessName === '') {
                    $businessName = $request->string('name')."'s Business";
                }

                $primaryIndustry = (string) $request->input('primary_industry', 'fnb');
                if (! in_array($primaryIndustry, ['fnb', 'retail', 'hybrid'], true)) {
                    $primaryIndustry = 'fnb';
                }

                $businessSubtype = (string) $request->input('business_subtype', '');
                if ($businessSubtype === '') {
                    $businessSubtype = match ($primaryIndustry) {
                        'retail' => 'minimarket',
                        'hybrid' => 'hybrid_store',
                        default => 'cafe',
                    };
                }

                $tenant = Tenant::create([
                    'name' => $businessName,
                    'slug' => Str::slug($businessName).'-'.Str::lower(Str::random(6)),
                    'status' => 'pending',
                    'onboarding_status' => Tenant::ONBOARDING_PENDING_VERIFICATION,
                    'access_status' => Tenant::ACCESS_PENDING,
                    'subscription_status' => Tenant::SUBSCRIPTION_TRIAL,
                    'subscription_plan' => 'standard_trial',
                    'country_code' => 'ID',
                    'primary_industry' => $primaryIndustry,
                    'business_subtype' => $businessSubtype,
                ]);

                app(TenantContext::class)->setTenantId($tenant->id);

                // Buat Role Owner untuk tenant ini
                $ownerRole = new Role;
                $ownerRole->tenant_id = $tenant->id;
                $ownerRole->name = 'Owner';
                $ownerRole->description = 'Tenant Owner';
                $ownerRole->permissions = ['*'];
                $ownerRole->is_active = true;
                $ownerRole->save();

                // Buat User Owner (belum terverifikasi)
                $user = new User;
                $user->tenant_id = $tenant->id;
                $user->role_id = $ownerRole->id;
                $user->name = $request->string('name');
                $user->email = $request->string('email');
                $user->email_normalized = $normalizedEmail;
                $user->password_hash = Hash::make($request->string('password'));
                $user->is_active = true;
                $user->email_verified_at = null;
                $user->failed_login_attempts = 0;
                $user->save();

                // Inisialisasi Cabang Pertama (Store Initial)
                $defaultChannel = $primaryIndustry === 'retail' ? 'counter_retail' : 'dine_in';
                $store = Store::create([
                    'tenant_id' => $tenant->id,
                    'name' => $businessName.' - Main Branch',
                    'code' => 'MAIN-01',
                    'timezone' => 'Asia/Jakarta',
                    'business_date_cutoff' => '00:00',
                    'store_vertical' => $primaryIndustry,
                    'default_channel' => $defaultChannel,
                    'is_active' => true,
                    'is_initial' => true,
                ]);

                DB::table('user_stores')->insertOrIgnore([
                    'tenant_id' => $tenant->id,
                    'user_id' => $user->id,
                    'store_id' => $store->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                // Mulai Langganan Trial jika Plan tersedia
                $plan = Plan::query()
                    ->where('code', 'standard_trial')
                    ->orWhere('code', 'trial')
                    ->orWhere('is_active', true)
                    ->first();
                if ($plan !== null) {
                    try {
                        app(SubscriptionLifecycleService::class)->startTrial($tenant, $plan, 14);
                    } catch (\Throwable $e) {
                        // Resilient fallback
                    }
                }

                // Seed COA & Aturan Posting
                try {
                    (new ChartOfAccountsSeeder)->seedForTenant($tenant->id);
                    Artisan::call('finance:sync-posting-rules', ['--tenant' => (string) $tenant->id]);
                } catch (\Throwable $e) {
                    // Resilient fallback
                }

                // Preset Pajak Sesuai Vertikal via DB builder (menjaga batasan ratchet scope)
                // F&B: PB1 10% + Service Charge 5%; Retail: PPN 11% + SC 0%; Hybrid: PPN 11% + SC 0%
                $taxRate = $primaryIndustry === 'fnb' ? '0.10' : '0.11';
                $scRate = $primaryIndustry === 'fnb' ? '0.05' : '0';
                $categoryKey = $primaryIndustry === 'fnb'
                    ? 'fnb_'.$businessSubtype
                    : ($primaryIndustry === 'retail' ? 'retail_'.$businessSubtype : 'hybrid_store');

                DB::table('settings')->updateOrInsert(
                    ['tenant_id' => $tenant->id, 'key' => 'tax_rate'],
                    ['value' => $taxRate, 'type' => 'string', 'updated_at' => now(), 'created_at' => now()]
                );
                DB::table('settings')->updateOrInsert(
                    ['tenant_id' => $tenant->id, 'key' => 'service_charge_rate'],
                    ['value' => $scRate, 'type' => 'string', 'updated_at' => now(), 'created_at' => now()]
                );
                DB::table('settings')->updateOrInsert(
                    ['tenant_id' => $tenant->id, 'key' => 'business_category'],
                    ['value' => $categoryKey, 'type' => 'string', 'updated_at' => now(), 'created_at' => now()]
                );

                // Terbitkan tantangan OTP 6-digit pertama kali
                app(EmailVerificationOtpService::class)->issueOtp(
                    $user,
                    'registration',
                    $request->ip(),
                    $request->userAgent()
                );

                // Autentikasi sesi pendaftar dan arahkan ke layar input OTP
                Auth::login($user);
                $request->session()->regenerate();
                app(TenantContext::class)->setTenantId($tenant->id);

                return redirect()->route('verification.notice');
            });
        } catch (QueryException $e) {
            if (DatabaseErrorClassifier::isUniqueConstraintViolation($e)) {
                return redirect()->route('login')
                    ->with('status', 'Pendaftaran berhasil diproses. Silakan periksa email Anda untuk instruksi verifikasi.');
            }

            throw $e;
        } finally {
            $lock->release();
        }
    }
}
