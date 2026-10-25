<?php

declare(strict_types=1);

namespace App\Support\Dataset;

/**
 * PRNG deterministik berbasis seed (mulberry32).
 *
 * Dipakai alih-alih mt_rand() global agar:
 *  - reproducible lintas run & lintas PHP version,
 *  - tiap domain bisa punya stream berbeda (memakai salt),
 *  - tidak terganggu pemanggilan rand() lain di framework.
 */
final class SeededRandom
{
    private int $state;

    private int $initialState;

    public function __construct(int $seed)
    {
        $this->initialState = $seed & 0xFFFFFFFF;
        $this->state = $this->initialState;
    }

    public function reset(): void
    {
        $this->state = $this->initialState;
    }

    /** Stream turunan (independent, deterministik) untuk domain tertentu. */
    public function derive(string $salt): self
    {
        return new self((int) (crc32($this->initialState.'|'.$salt) & 0x7FFFFFFF));
    }

    private function next(): int
    {
        $this->state = ($this->state + 0x6D2B79F5) & 0xFFFFFFFF;
        $t = $this->state;
        $t = self::imul($t ^ ($t >> 15), $t | 1);
        $t ^= ($t + self::imul($t ^ ($t >> 7), $t | 61)) & 0xFFFFFFFF;
        $t = ($t ^ ($t >> 14)) & 0xFFFFFFFF;
        $this->state = ($this->state ^ $t) & 0xFFFFFFFF;

        return $t;
    }

    private static function imul(int $a, int $b): int
    {
        $ah = ($a >> 16) & 0xFFFF;
        $al = $a & 0xFFFF;
        $bh = ($b >> 16) & 0xFFFF;
        $bl = $b & 0xFFFF;

        return (($al * $bl) + (((($ah * $bl) + ($al * $bh)) & 0xFFFF) << 16)) & 0xFFFFFFFF;
    }

    /** Float 0.0 .. <1.0 */
    public function float(): float
    {
        return $this->next() / 4294967296.0;
    }

    /** Integer inklusif [min, max]. */
    public function int(int $min, int $max): int
    {
        if ($max <= $min) {
            return $min;
        }

        return $min + ($this->next() % ($max - $min + 1));
    }

    /** Float inklusif-eksklusif [min, max). */
    public function floatBetween(float $min, float $max): float
    {
        return $min + $this->float() * ($max - $min);
    }

    public function bool(float $probability = 0.5): bool
    {
        return $this->float() < $probability;
    }

    /**
     * Pilih key berbobot. $weights = [key => weight].
     */
    public function weighted(array $weights): int|string|null
    {
        $total = 0;
        foreach ($weights as $w) {
            $total += max(0, (int) $w);
        }
        if ($total <= 0) {
            return array_key_first($weights);
        }

        $roll = $this->int(1, $total);
        foreach ($weights as $key => $w) {
            $roll -= max(0, (int) $w);
            if ($roll <= 0) {
                return $key;
            }
        }

        return array_key_last($weights);
    }

    public function pick(array $items): mixed
    {
        if ($items === []) {
            return null;
        }
        $values = array_values($items);

        return $values[$this->int(0, count($values) - 1)];
    }

    /** @return array<int, mixed> $count item unik (jika cukup). */
    public function sample(array $items, int $count): array
    {
        $values = array_values($items);
        $n = count($values);
        if ($n === 0 || $count <= 0) {
            return [];
        }
        $count = min($count, $n);
        $result = [];
        $used = [];
        while (count($result) < $count) {
            $idx = $this->int(0, $n - 1);
            if (isset($used[$idx])) {
                continue;
            }
            $used[$idx] = true;
            $result[] = $values[$idx];
        }

        return $result;
    }

    /** Distribusi normal terklip (Box-Muller sederhana). */
    public function normal(float $mean, float $stdDev, float $min = -INF, float $max = INF): float
    {
        $u1 = max(1e-9, $this->float());
        $u2 = $this->float();
        $z = sqrt(-2.0 * log($u1)) * cos(2.0 * M_PI * $u2);

        return max($min, min($max, $mean + $z * $stdDev));
    }

    /** Jitter menit untuk waktu transaksi. */
    public function minutes(float $mean, float $stdDev, int $min, int $max): int
    {
        return (int) round($this->normal($mean, $stdDev, $min, $max));
    }
}
