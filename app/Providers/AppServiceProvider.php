<?php

namespace App\Providers;

use App\Http\Middleware\SetTenantContext;
use App\Models\Ingredient;
use App\Models\Product;
use App\Modules\Finance\Contracts\PostingEngineContract;
use App\Modules\Finance\Services\PostingEngineService;
use App\Policies\CustomerPolicy;
use App\Policies\GrnPolicy;
use App\Policies\StoreCreditPolicy;
use App\Services\Context\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

/**
 * AppServiceProvider merupakan pusat pengaturan awal aplikasi
 * untuk menghubungkan Service pembantu dan injeksi Dependensi sistem.
 */
class AppServiceProvider extends ServiceProvider
{
    /**
     * Menyuntikkan (Register) instance ke wadah penyimpanan Container.
     */
    public function register(): void
    {
        $this->app->scoped(TenantContext::class, function () {
            return new TenantContext;
        });

        $this->app->alias(TenantContext::class, \App\Core\Context\TenantContext::class);

        $this->app->singleton(
            PostingEngineContract::class,
            PostingEngineService::class
        );
    }

    /**
     * Mengeksekusi penyesuaian logika (Bootstrapping) pasca load sistem awal.
     */
    public function boot(): void
    {
        Livewire::addPersistentMiddleware([
            SetTenantContext::class,
            'tenant.context',
            'role',
        ]);

        Gate::guessPolicyNamesUsing(static function (string $modelClass): array|string {
            $base = class_basename($modelClass);

            $explicitPolicyMap = [
                'GoodsReceivedNote' => GrnPolicy::class,
                'CustomerGroup' => CustomerPolicy::class,
                'StoreCreditAccount' => StoreCreditPolicy::class,
            ];

            if (isset($explicitPolicyMap[$base])) {
                return $explicitPolicyMap[$base];
            }

            $policyClass = "App\\Policies\\{$base}Policy";

            return class_exists($policyClass) ? $policyClass : [];
        });

        Relation::morphMap([
            'product' => Product::class,
            'ingredient' => Ingredient::class,
        ]);

        // PATCH FOR S-06: gagal cepat saat boot produksi jika server key kosong.
        if ($this->app->isProduction() && blank(config('services.midtrans.server_key'))) {
            throw new \RuntimeException('MIDTRANS_SERVER_KEY wajib diisi di produksi.');
        }

        // PATCH FOR SEC-003 / SEC-016 / OR-012: Jangan gunakan mailer log di production (mencegah PII/token leak ke logs)
        if ($this->app->isProduction()) {
            $defaultMailer = (string) config('mail.default');
            $mailersConfig = config('mail.mailers', []);

            $hasLogTransport = function (string $mailerName) use (&$hasLogTransport, $mailersConfig): bool {
                $mailer = $mailersConfig[$mailerName] ?? [];
                $transport = $mailer['transport'] ?? $mailerName;
                if ($transport === 'log') {
                    return true;
                }
                if (in_array($transport, ['failover', 'roundrobin'], true)) {
                    foreach ($mailer['mailers'] ?? [] as $subMailer) {
                        if ($hasLogTransport($subMailer)) {
                            return true;
                        }
                    }
                }

                return false;
            };

            if ($hasLogTransport($defaultMailer)) {
                throw new \RuntimeException('Mail driver "log" dilarang di production (SEC-003/SEC-016 PII leak).');
            }
        }

        // PATCH FOR S-04: rate limiter bernama dengan kunci per-session, bukan per-IP saja.
        $sid = fn (Request $r) => $r->hasSession() ? $r->session()->getId() : 'nosession';
        $table = fn (Request $r) => $r->hasSession() ? (string) $r->session()->get('current_table_id', '-') : '-';

        RateLimiter::for('customer-menu', fn (Request $r) => [
            Limit::perMinute(60)->by('ip:'.$r->ip()),
            Limit::perMinute(120)->by('sess:'.$sid($r)),
        ]);

        RateLimiter::for('customer-cart', fn (Request $r) => [
            Limit::perMinute(60)->by('sess:'.$sid($r)),
            Limit::perMinute(400)->by('table:'.$table($r)),
        ]);

        RateLimiter::for('customer-checkout', fn (Request $r) => [
            Limit::perMinute(5)->by('sess:'.$sid($r)),
            Limit::perMinute(20)->by('table:'.$table($r)),
            Limit::perMinute(200)->by('ip:'.$r->ip()),
        ]);

        RateLimiter::for('customer-poll', fn (Request $r) => Limit::perMinute(20)->by('sess:'.$sid($r)));

        RateLimiter::for('voucher-check', fn (Request $r) => [
            Limit::perMinute(5)->by('ip:'.$r->ip()),
            Limit::perMinute(10)->by('user:'.($r->user()?->id ?? $sid($r))),
        ]);
    }
}
