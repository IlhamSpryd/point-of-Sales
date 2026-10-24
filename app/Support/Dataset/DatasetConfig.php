<?php

declare(strict_types=1);

namespace App\Support\Dataset;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Konfigurasi dataset yang immutable dan sudah divalidasi.
 *
 * Dibuat dari config/dataset.php + override CLI. Logika generator TIDAK
 * membaca config langsung — semua nilai masuk lewat objek ini agar perilaku
 * deterministik, auditable, dan mudah diuji.
 */
final class DatasetConfig
{
    /**
     * @param  array<int, array{email:string,name:string,tenant_name:string,tenant_slug:string,stores:array<int,array<string,mixed>>}>  $accounts
     * @param  array<string, mixed>  $volume
     * @param  array<string, mixed>  $behavior
     * @param  array<string, mixed>  $procurement
     * @param  array<string, mixed>  $period
     */
    public function __construct(
        public readonly string $runId,
        public readonly int $seed,
        public readonly int $batchSize,
        public readonly int $daysPerCheckpoint,
        public readonly CarbonImmutable $startDate,
        public readonly CarbonImmutable $endDate,
        public readonly array $accounts,
        public readonly array $volume,
        public readonly array $behavior,
        public readonly array $procurement,
        public readonly array $period,
        public readonly string $planCode,
        public readonly string $cashierPin,
        public readonly ?string $password,
        public readonly string $markerPrefix,
        public readonly bool $dryRun = false,
        public readonly ?int $limitDays = null,
        public readonly ?string $onlyAccountEmail = null,
        public readonly bool $resume = false,
    ) {}

    public static function fromArray(array $config, array $overrides = [], ?string $password = null): self
    {
        $volume = array_replace($config['volume'] ?? [], $overrides['volume'] ?? []);
        $behavior = array_replace($config['behavior'] ?? [], $overrides['behavior'] ?? []);
        $procurement = array_replace($config['procurement'] ?? [], $overrides['procurement'] ?? []);
        $periodCfg = $config['period'] ?? [];

        $tz = (string) ($periodCfg['timezone'] ?? 'Asia/Jakarta');
        $end = ! empty($overrides['end_date'])
            ? CarbonImmutable::parse($overrides['end_date'], $tz)->endOfDay()
            : (! empty($periodCfg['end_date'])
                ? CarbonImmutable::parse($periodCfg['end_date'], $tz)->endOfDay()
                : CarbonImmutable::today($tz)->endOfDay());

        $months = (int) ($overrides['months'] ?? $periodCfg['months'] ?? 12);
        if ($months < 1 || $months > 36) {
            throw new InvalidArgumentException("Jumlah bulan dataset harus 1..36, diberi {$months}.");
        }

        $start = ! empty($overrides['start_date'])
            ? CarbonImmutable::parse($overrides['start_date'], $tz)->startOfDay()
            : (! empty($periodCfg['start_date'])
                ? CarbonImmutable::parse($periodCfg['start_date'], $tz)->startOfDay()
                : $end->startOfDay()->subMonthsNoOverflow($months - 1)->startOfMonth());

        if ($start->greaterThanOrEqualTo($end)) {
            throw new InvalidArgumentException('start_date harus lebih awal dari end_date.');
        }

        $seed = (int) ($overrides['seed'] ?? $config['random_seed'] ?? 1);
        $runId = (string) ($overrides['run_id'] ?? $config['marker']['run_id'] ?? '') ?: self::defaultRunId($seed, $start, $end);

        $accounts = $config['demo_accounts'] ?? [];
        if ($accounts === []) {
            throw new InvalidArgumentException('config/dataset.php tidak memiliki demo_accounts.');
        }

        return new self(
            runId: $runId,
            seed: $seed,
            batchSize: max(50, (int) ($overrides['batch_size'] ?? $config['batch_size'] ?? 500)),
            daysPerCheckpoint: max(1, (int) ($overrides['days_per_checkpoint'] ?? $config['days_per_checkpoint'] ?? 1)),
            startDate: $start,
            endDate: $end,
            accounts: $accounts,
            volume: $volume,
            behavior: $behavior,
            procurement: $procurement,
            period: $periodCfg,
            planCode: (string) ($config['accounts']['plan_code'] ?? 'enterprise'),
            cashierPin: (string) ($config['accounts']['cashier_pin'] ?? '135790'),
            password: $password,
            markerPrefix: (string) ($config['marker']['prefix'] ?? 'ds'),
            dryRun: (bool) ($overrides['dry_run'] ?? false),
            limitDays: isset($overrides['limit_days']) ? max(1, (int) $overrides['limit_days']) : null,
            onlyAccountEmail: $overrides['only_account'] ?? null,
            resume: (bool) ($overrides['resume'] ?? false),
        );
    }

    public static function defaultRunId(int $seed, CarbonImmutable $start, CarbonImmutable $end): string
    {
        return sprintf('r%s-%d-%d', $seed, $start->format('Ymd'), $end->format('Ymd'));
    }

    /** @return array<int, array<string, mixed>> */
    public function accounts(): array
    {
        if ($this->onlyAccountEmail === null) {
            return $this->accounts;
        }

        $filtered = array_values(array_filter(
            $this->accounts,
            fn (array $a) => strcasecmp($a['email'], (string) $this->onlyAccountEmail) === 0
        ));

        if ($filtered === []) {
            throw new InvalidArgumentException("Akun demo {$this->onlyAccountEmail} tidak ada di config/dataset.php.");
        }

        return $filtered;
    }

    public function hasPassword(): bool
    {
        return $this->password !== null && $this->password !== '';
    }

    /** Tanggal akhir efektif (dibatasi limitDays untuk smoke test). */
    public function effectiveEndDate(): CarbonImmutable
    {
        if ($this->limitDays === null) {
            return $this->endDate;
        }

        $candidate = $this->startDate->addDays($this->limitDays - 1)->endOfDay();

        return $candidate->lessThan($this->endDate) ? $candidate : $this->endDate;
    }

    public function totalDays(): int
    {
        return (int) $this->startDate->startOfDay()->diffInDays($this->effectiveEndDate()->startOfDay()) + 1;
    }

    public function volumeInt(string $key, int $default = 0): int
    {
        return (int) ($this->volume[$key] ?? $default);
    }

    public function behaviorFloat(string $key, float $default = 0.0): float
    {
        return (float) ($this->behavior[$key] ?? $default);
    }

    public function marker(string $suffix = ''): string
    {
        // Panjang kolom idempotency berbeda per tabel (orders=36, purchase_orders=64).
        // Suffix di-hash agar marker selalu pendek, stabil, dan bebas karakter aneh.
        $base = "{$this->markerPrefix}:{$this->runId}";
        if ($suffix === '') {
            return substr($base, 0, 36);
        }

        return 'ds:'.substr(hash('sha256', $base.'|'.$suffix), 0, 32);
    }

    /** Identitas dataset untuk disimpan/ditampilkan (tanpa secret). */
    public function fingerprint(): string
    {
        return substr(hash('sha256', implode('|', [
            $this->runId, $this->seed, $this->startDate->toDateString(),
            $this->endDate->toDateString(), json_encode($this->accounts),
            json_encode($this->volume), json_encode($this->behavior),
        ])), 0, 16);
    }
}
