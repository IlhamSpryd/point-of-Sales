<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

use Illuminate\Support\Facades\Schedule;

Schedule::command('backup:run')
    ->dailyAt('02:00')
    ->withoutOverlapping(120)
    ->onOneServer();

// Recompute SETELAH jam operasional biasa agar agregasi
// 14-hari tidak bersaing dengan jam sibuk kafe.
Schedule::command('analytics:recompute-restock-forecasts')
    ->dailyAt('03:30')
    ->withoutOverlapping(60)
    ->onOneServer()
    ->runInBackground();

// [SPRINT-0] Kedaluwarsakan self-order cash yang belum dibayar di kasir
Schedule::command('orders:expire-stale-cash')
    ->everyFiveMinutes()
    ->withoutOverlapping(10)
    ->onOneServer()
    ->runInBackground();

// PATCH FOR SO-01: Rekonsiliasi Midtrans order
Schedule::command('orders:reconcile-midtrans')
    ->everyTenMinutes()
    ->withoutOverlapping(15)
    ->onOneServer()
    ->runInBackground();

/*
| Domain 1 — Outbox email provisioning & cleanup nonce anti-replay.
| Outbox diproses tiap menit (fallback bila dispatch job setelah commit
| gagal — D8, "tidak ada event hilang"). Nonce kedaluwarsa dibersihkan
| setiap 10 menit; retensi >= 2x timestamp window tetap ditegakkan oleh
| TTL kolom expires_at_epoch.
*/
Schedule::command('emails:process-outbox')
    ->everyMinute()
    ->withoutOverlapping(5)
    ->onOneServer()
    ->runInBackground();

Schedule::command('provisioning:prune-nonces')
    ->everyTenMinutes()
    ->withoutOverlapping(10)
    ->onOneServer()
    ->runInBackground();

Schedule::command('provisioning:reconcile')
    ->everyFiveMinutes()
    ->withoutOverlapping(10)
    ->onOneServer()
    ->runInBackground();

Schedule::command('identity:prune-challenges')
    ->dailyAt('04:00')
    ->withoutOverlapping(30)
    ->onOneServer()
    ->runInBackground();
