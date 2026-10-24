<?php

namespace App\Modules\Auth\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\EmailOutbox;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Context\TenantContext;
use App\Services\DatabaseErrorClassifier;
use App\Services\EmailVerificationOtpService;
use App\Support\EmailNormalizer;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

                $tenant = Tenant::create([
                    'name' => $businessName,
                    'slug' => Str::slug($businessName).'-'.Str::lower(Str::random(6)),
                    'status' => 'pending',
                    'onboarding_status' => Tenant::ONBOARDING_PENDING_VERIFICATION,
                    'access_status' => Tenant::ACCESS_PENDING,
                    'subscription_status' => Tenant::SUBSCRIPTION_TRIAL,
                    'subscription_plan' => 'standard_trial',
                    'country_code' => 'ID',
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
