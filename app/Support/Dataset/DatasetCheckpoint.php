<?php

declare(strict_types=1);

namespace App\Support\Dataset;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Checkpoint per akun/dataset agar generator bisa di-resume tanpa duplikasi.
 *
 * Menggunakan tabel `settings` (sudah ada, tenant-scoped, tanpa perubahan skema).
 * Nilai disimpan sebagai JSON di key `dataset.checkpoint.<runId>`.
 */
final class DatasetCheckpoint
{
    private const KEY = 'dataset.checkpoint';

    /** @return array<string,mixed> */
    public function load(int $tenantId, string $runId): array
    {
        try {
            $value = DB::table('settings')
                ->where('tenant_id', $tenantId)
                ->where('key', self::KEY.'.'.$runId)
                ->value('value');
        } catch (\Throwable) {
            return [];
        }

        if (! is_string($value) || $value === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string,mixed> $data */
    public function save(int $tenantId, string $runId, array $data): void
    {
        $payload = array_merge($data, ['updated_at' => now()->toIso8601String()]);

        DB::table('settings')->updateOrInsert(
            ['tenant_id' => $tenantId, 'key' => self::KEY.'.'.$runId],
            [
                'value' => json_encode($payload),
                'type' => 'json',
                'description' => 'Checkpoint generator dataset (idempoten, boleh dihapus untuk reset).',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    public function completedDay(int $tenantId, string $runId): ?CarbonImmutable
    {
        $day = $this->load($tenantId, $runId)['last_completed_day'] ?? null;

        return is_string($day) && $day !== '' ? CarbonImmutable::parse($day) : null;
    }

    public function markDay(int $tenantId, string $runId, CarbonImmutable $day, array $metrics = []): void
    {
        $data = $this->load($tenantId, $runId);
        $data['last_completed_day'] = $day->toDateString();
        $data['counters'] = $metrics;

        $this->save($tenantId, $runId, $data);
    }
}
