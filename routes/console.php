<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

use Illuminate\Support\Facades\Schedule;

Schedule::command('backup:run')->dailyAt('02:00');

// [OMEGA-NODE5] Recompute SETELAH jam operasional biasa agar agregasi
// 14-hari tidak bersaing dengan jam sibuk kafe. | 2026-09-23
Schedule::command('analytics:recompute-restock-forecasts')->dailyAt('03:30');

// [SPRINT-0] Kedaluwarsakan self-order cash yang belum dibayar di kasir
Schedule::command('orders:expire-stale-cash')->everyFiveMinutes();

// PATCH FOR SO-01: Rekonsiliasi Midtrans order
Schedule::command('orders:reconcile-midtrans')->everyTenMinutes();
