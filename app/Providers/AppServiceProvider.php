<?php

namespace App\Providers;

use App\Http\Middleware\SetTenantContext;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceivedNote;
use App\Models\Ingredient;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\PurchaseReturn;
use App\Models\StockAdjustment;
use App\Models\StockOpname;
use App\Models\StockTransfer;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\SupplierReturn;
use App\Policies\GoodsReceiptPolicy;
use App\Policies\GrnPolicy;
use App\Policies\PurchaseOrderPolicy;
use App\Policies\PurchaseRequestPolicy;
use App\Policies\PurchaseReturnPolicy;
use App\Policies\StockAdjustmentPolicy;
use App\Policies\StockOpnamePolicy;
use App\Policies\StockTransferPolicy;
use App\Policies\SupplierInvoicePolicy;
use App\Policies\SupplierPolicy;
use App\Policies\SupplierReturnPolicy;
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

        Gate::policy(GoodsReceipt::class, GoodsReceiptPolicy::class);
        Gate::policy(\App\Modules\Inventory\Models\GoodsReceipt::class, GoodsReceiptPolicy::class);
        Gate::policy(StockAdjustment::class, StockAdjustmentPolicy::class);
        Gate::policy(\App\Modules\Inventory\Models\StockAdjustment::class, StockAdjustmentPolicy::class);
        Gate::policy(StockOpname::class, StockOpnamePolicy::class);
        Gate::policy(\App\Modules\Inventory\Models\StockOpname::class, StockOpnamePolicy::class);
        Gate::policy(StockTransfer::class, StockTransferPolicy::class);
        Gate::policy(\App\Modules\Inventory\Models\StockTransfer::class, StockTransferPolicy::class);
        Gate::policy(SupplierReturn::class, SupplierReturnPolicy::class);
        Gate::policy(\App\Modules\Inventory\Models\SupplierReturn::class, SupplierReturnPolicy::class);
        Gate::policy(Supplier::class, SupplierPolicy::class);
        Gate::policy(\App\Modules\Purchasing\Models\Supplier::class, SupplierPolicy::class);
        Gate::policy(PurchaseRequest::class, PurchaseRequestPolicy::class);
        Gate::policy(\App\Modules\Purchasing\Models\PurchaseRequest::class, PurchaseRequestPolicy::class);
        Gate::policy(PurchaseOrder::class, PurchaseOrderPolicy::class);
        Gate::policy(\App\Modules\Purchasing\Models\PurchaseOrder::class, PurchaseOrderPolicy::class);
        Gate::policy(GoodsReceivedNote::class, GrnPolicy::class);
        Gate::policy(\App\Modules\Purchasing\Models\GoodsReceivedNote::class, GrnPolicy::class);
        Gate::policy(SupplierInvoice::class, SupplierInvoicePolicy::class);
        Gate::policy(\App\Modules\Purchasing\Models\SupplierInvoice::class, SupplierInvoicePolicy::class);
        Gate::policy(PurchaseReturn::class, PurchaseReturnPolicy::class);
        Gate::policy(\App\Modules\Purchasing\Models\PurchaseReturn::class, PurchaseReturnPolicy::class);

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
    }
}
