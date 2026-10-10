<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

use Illuminate\Support\Facades\Schedule;

Schedule::command('backup:run')->dailyAt('02:00');

// Recompute SETELAH jam operasional biasa agar agregasi
// 14-hari tidak bersaing dengan jam sibuk kafe.
Schedule::command('analytics:recompute-restock-forecasts')->dailyAt('03:30');

// [SPRINT-0] Kedaluwarsakan self-order cash yang belum dibayar di kasir
Schedule::command('orders:expire-stale-cash')->everyFiveMinutes();

// PATCH FOR SO-01: Rekonsiliasi Midtrans order
Schedule::command('orders:reconcile-midtrans')->everyTenMinutes();

/*
| Domain 1 — Outbox email provisioning & cleanup nonce anti-replay.
| Outbox diproses tiap menit (fallback bila dispatch job setelah commit
| gagal — D8, "tidak ada event hilang"). Nonce kedaluwarsa dibersihkan
| setiap 10 menit; retensi >= 2x timestamp window tetap ditegakkan oleh
| TTL kolom expires_at_epoch.
*/
Schedule::command('emails:process-outbox')->everyMinute();
Schedule::command('provisioning:prune-nonces')->everyTenMinutes();
