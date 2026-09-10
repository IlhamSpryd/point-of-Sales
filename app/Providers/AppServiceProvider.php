<?php

namespace App\Providers;

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
        //
    }
}
