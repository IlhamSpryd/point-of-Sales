<?php

namespace App\Providers;

use App\Core\Auth\TenantAwareUserProvider;
use App\Core\Context\TenantContext;
use App\Http\Middleware\SetTenantContext;
use App\Modules\Catalog\Models\Product;
use App\Modules\Finance\Contracts\PostingEngineContract;
use App\Modules\Finance\Contracts\TaxCalculationServiceInterface;
use App\Modules\Finance\Contracts\TaxIntegrationGatewayInterface;
use App\Modules\Finance\Contracts\TaxIntegrationServiceInterface;
use App\Modules\Finance\Services\CoreTaxDjpGateway;
use App\Modules\Finance\Services\PostingEngineService;
use App\Modules\Finance\Services\TaxCalculationService;
use App\Modules\Finance\Services\TaxIntegrationService;
use App\Modules\Integration\Contracts\WhatsAppGatewayInterface;
use App\Modules\Integration\Services\Adapters\QiscusWhatsAppAdapter;
use App\Modules\Inventory\Models\Ingredient;
use App\Policies\CustomerPolicy;
use App\Policies\GrnPolicy;
use App\Policies\StoreCreditPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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

        $this->app->singleton(
            PostingEngineContract::class,
            PostingEngineService::class
        );

        $this->app->bind(
            TaxIntegrationGatewayInterface::class,
            CoreTaxDjpGateway::class
        );

        $this->app->bind(
            TaxCalculationServiceInterface::class,
            TaxCalculationService::class
        );

        $this->app->singleton(
            TaxIntegrationServiceInterface::class,
            TaxIntegrationService::class
        );

        $this->app->bind(
            WhatsAppGatewayInterface::class,
            QiscusWhatsAppAdapter::class
        );

        $this->app->bind(
            \App\Modules\Customers\Contracts\CustomerSegmentReaderInterface::class,
            \App\Modules\Customers\Services\CustomerService::class
        );

        $this->app->bind(
            \App\Modules\Integration\Contracts\PaymentGatewayInterface::class,
            \App\Modules\Integration\Services\MidtransPaymentGatewayAdapter::class
        );

        $this->app->bind(
            \App\Modules\Subscription\Contracts\EntitlementGateInterface::class,
            \App\Modules\Subscription\Services\EntitlementService::class
        );

        $this->app->bind(
            \App\Modules\Subscription\Contracts\SubscriptionPaymentSettlementInterface::class,
            \App\Modules\Subscription\Application\Services\SubscriptionBillingOrchestrator::class
        );

        $this->app->singleton(\App\Platform\Authorization\AbilityRegistry::class);
        $this->app->singleton(\App\Platform\Authorization\AbilityEvaluator::class);

        // Navigation (ADR-NAV-001). The registry is a pure-data singleton; the
        // resolver is request-scoped so its per-user visibility memoisation never
        // leaks across requests and navigation render issues zero repeat queries.
        $this->app->singleton(\App\Platform\Navigation\NavigationRegistry::class);
        $this->app->scoped(\App\Platform\Navigation\NavigationVisibilityResolver::class);
    }

    /**
     * Mengeksekusi penyesuaian logika (Bootstrapping) pasca load sistem awal.
     */
    public function boot(): void
    {
        if ($this->app->isProduction()) {
            \Illuminate\Support\Facades\URL::forceScheme('https');
        }

        ini_set('unserialize_callback_func', 'spl_autoload_call');

        Factory::guessFactoryNamesUsing(static function (string $modelName): string {
            return 'Database\\Factories\\'.class_basename($modelName).'Factory';
        });

        Auth::provider('eloquent', function ($app, array $config) {
            return new TenantAwareUserProvider($app['hash'], $config['model']);
        });

        Livewire::addPersistentMiddleware([
            SetTenantContext::class,
            'tenant.context',
            'role',
        ]);

        Gate::before(static function ($user, string $ability, array $arguments = []): ?bool {
            if ($user instanceof \App\Modules\Organization\Models\User) {
                /** @var \App\Platform\Authorization\AbilityRegistry $registry */
                $registry = app(\App\Platform\Authorization\AbilityRegistry::class);
                if ($registry->has($ability)) {
                    $context = [];
                    if (isset($arguments[0]) && is_array($arguments[0])) {
                        $context = $arguments[0];
                    }
                    /** @var \App\Platform\Authorization\AbilityEvaluator $evaluator */
                    $evaluator = app(\App\Platform\Authorization\AbilityEvaluator::class);

                    return $evaluator->can($user, $ability, $context);
                }
            }

            return null;
        });

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
