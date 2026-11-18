<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Platform\Observability\DomainMetricsCollector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class HealthCheckController extends Controller
{
    /**
     * Liveness Probe (/health/live)
     * Mengembalikan HTTP 200 jika proses aplikasi hidup tanpa melakukan pemeriksaan dependensi eksternal
     * yang berpotensi memicu restart loop pada orchestrator.
     */
    public function live(): JsonResponse
    {
        return response()->json([
            'status' => 'UP',
            'timestamp' => now()->toIso8601String(),
        ], Response::HTTP_OK);
    }

    /**
     * Readiness Probe (/health/ready)
     * Memverifikasi dependensi kritis: Database, Cache/Redis, Queue configuration, dan Local Storage
     * dengan pesan ter-sanitasi (tidak membocorkan kredensial, SQL, maupun path sistem internal).
     * Mendukung ?deep=1 untuk inspeksi mendalam (outbox backlog health).
     */
    public function ready(Request $request): JsonResponse
    {
        $checks = [];
        $isHealthy = true;

        // 1. Database Connectivity Check
        try {
            DB::select('SELECT 1');
            $checks['database'] = [
                'status' => 'UP',
                'message' => 'Database connection healthy',
            ];
        } catch (\Throwable) {
            $isHealthy = false;
            $checks['database'] = [
                'status' => 'DOWN',
                'message' => 'Database connection unavailable',
            ];
        }

        // 2. Cache / Redis Check
        try {
            $cacheKey = 'health_check_ping_'.bin2hex(random_bytes(4));
            Cache::put($cacheKey, 'pong', 5);
            $cacheVal = Cache::get($cacheKey);
            Cache::forget($cacheKey);

            if ($cacheVal === 'pong') {
                $checks['cache'] = [
                    'status' => 'UP',
                    'message' => 'Cache read/write healthy',
                ];
            } else {
                $isHealthy = false;
                $checks['cache'] = [
                    'status' => 'DOWN',
                    'message' => 'Cache verification failed',
                ];
            }
        } catch (\Throwable) {
            $isHealthy = false;
            $checks['cache'] = [
                'status' => 'DOWN',
                'message' => 'Cache service unavailable',
            ];
        }

        // 3. Queue Driver Readiness Check
        try {
            $defaultQueue = (string) config('queue.default', 'sync');
            $queueConfig = config("queue.connections.{$defaultQueue}");

            if (is_array($queueConfig) && isset($queueConfig['driver'])) {
                $checks['queue'] = [
                    'status' => 'UP',
                    'message' => 'Queue connection configured',
                ];
            } else {
                $isHealthy = false;
                $checks['queue'] = [
                    'status' => 'DOWN',
                    'message' => 'Queue connection misconfigured',
                ];
            }
        } catch (\Throwable) {
            $isHealthy = false;
            $checks['queue'] = [
                'status' => 'DOWN',
                'message' => 'Queue service unavailable',
            ];
        }

        // 4. Storage Availability Check (> 1% sisa ruang disk dan direktori writable)
        try {
            $storagePath = storage_path();
            $isWritable = is_writable($storagePath);
            $freeBytes = @disk_free_space($storagePath);
            $totalBytes = @disk_total_space($storagePath);

            if (! $isWritable) {
                $isHealthy = false;
                $checks['storage'] = [
                    'status' => 'DOWN',
                    'message' => 'Storage directory is not writable',
                ];
            } elseif ($freeBytes !== false && $totalBytes !== false && $totalBytes > 0) {
                $freePercent = ($freeBytes / $totalBytes) * 100;
                $storageHealthy = $freePercent >= 1.0;
                if (! $storageHealthy) {
                    $isHealthy = false;
                }
                $checks['storage'] = [
                    'status' => $storageHealthy ? 'UP' : 'DOWN',
                    'free_percent' => round($freePercent, 2),
                ];
            } else {
                $checks['storage'] = [
                    'status' => 'UP',
                    'message' => 'Storage directory writable',
                ];
            }
        } catch (\Throwable) {
            $isHealthy = false;
            $checks['storage'] = [
                'status' => 'DOWN',
                'message' => 'Storage check unavailable',
            ];
        }

        // 5. Deep Health Check (OPS-02, TARGET_SYSTEM_ARCHITECTURE §8.3)
        if ($request->boolean('deep') || $request->query('deep') === '1') {
            try {
                $metricsCollector = app(DomainMetricsCollector::class);
                $metrics = $metricsCollector->collect();

                // Alert if pending age is over 1 hour (3600s) with pending items
                $outboxHealthy = ! ($metrics['outbox_pending_count'] > 0 && $metrics['outbox_oldest_pending_age_seconds'] > 3600);

                if (! $outboxHealthy) {
                    $isHealthy = false;
                }

                $checks['outbox'] = [
                    'status' => $outboxHealthy ? 'UP' : 'DOWN',
                    'pending_count' => $metrics['outbox_pending_count'],
                    'dead_letter_count' => $metrics['outbox_dead_letter_count'],
                    'oldest_pending_age_seconds' => $metrics['outbox_oldest_pending_age_seconds'],
                ];
            } catch (\Throwable) {
                $isHealthy = false;
                $checks['outbox'] = [
                    'status' => 'DOWN',
                    'message' => 'Outbox health check unavailable',
                ];
            }
        }

        $statusCode = $isHealthy ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE;

        return response()->json([
            'status' => $isHealthy ? 'UP' : 'DOWN',
            'timestamp' => now()->toIso8601String(),
            'checks' => $checks,
        ], $statusCode);
    }
}
