<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

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
        //
    }

    /**
     * Mengeksekusi penyesuaian logika (Bootstrapping) pasca load sistem awal.
     */
    public function boot(): void
    {
        // PATCH FOR S-06: gagal cepat saat boot produksi jika server key kosong.
        if ($this->app->isProduction() && blank(config('services.midtrans.server_key'))) {
            throw new \RuntimeException('MIDTRANS_SERVER_KEY wajib diisi di produksi.');
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
