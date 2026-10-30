<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Services;

class DiscountAllocationService
{
    /**
     * Mengalokasikan diskon transaksi ke baris-baris item secara proporsional
     * menggunakan Largest Remainder Method (Hare-Niemeyer Method).
     *
     * @param  float  $totalDiscount  Total diskon transaksi yang akan dialokasikan
     * @param  array<int|string, float|array<string, mixed>>  $lineNets  Nilai bersih subtotal tiap baris (Line Net)
     * @return array<int|string, float> Alokasi diskon per baris dengan indeks identik
     */
    public function allocate(float $totalDiscount, array $lineNets): array
    {
        $normalizedLineNets = [];
        foreach ($lineNets as $k => $val) {
            if (is_array($val)) {
                $idx = $val['id'] ?? $val['line_index'] ?? $k;
                $amount = (float) ($val['effective_subtotal'] ?? $val['net_subtotal'] ?? $val['amount'] ?? 0.0);
                $normalizedLineNets[$idx] = $amount;
            } else {
                $normalizedLineNets[$k] = (float) $val;
            }
        }
        $lineNets = $normalizedLineNets;

        $allocated = array_fill_keys(array_keys($lineNets), 0.0);

        if ($totalDiscount <= 0.0 || empty($lineNets)) {
            return $allocated;
        }

        $totalNet = array_sum($lineNets);
        if ($totalNet <= 0.0) {
            return $allocated;
        }

        // Tidak boleh mendiskon melebihi total net barang
        $effectiveDiscount = min($totalDiscount, $totalNet);
        $totalCents = (int) round($effectiveDiscount * 100);

        if ($totalCents <= 0) {
            return $allocated;
        }

        $lineCents = [];
        $remainders = [];
        $sumBaseCents = 0;

        foreach ($lineNets as $idx => $net) {
            $ratio = $net / $totalNet;
            $exactCents = $totalCents * $ratio;
            $baseCents = (int) floor($exactCents);
            $rem = $exactCents - $baseCents;

            $lineCents[$idx] = $baseCents;
            $remainders[$idx] = [
                'index' => $idx,
                'remainder' => $rem,
                'net' => $net,
            ];
            $sumBaseCents += $baseCents;
        }

        $centsLeft = $totalCents - $sumBaseCents;

        if ($centsLeft > 0) {
            // Urutkan sisa desimal terbesar ke terkecil. Tie breaker: net terbesar, lalu index terkecil.
            uasort($remainders, function ($a, $b) {
                if ($a['remainder'] != $b['remainder']) {
                    return ($a['remainder'] < $b['remainder']) ? 1 : -1;
                }
                if ($a['net'] != $b['net']) {
                    return ($a['net'] < $b['net']) ? 1 : -1;
                }

                return $a['index'] <=> $b['index'];
            });

            foreach ($remainders as $item) {
                if ($centsLeft <= 0) {
                    break;
                }
                $lineCents[$item['index']]++;
                $centsLeft--;
            }
        }

        foreach ($lineCents as $idx => $cents) {
            $allocated[$idx] = round($cents / 100, 2);
        }

        return $allocated;
    }
}
