<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\PreparationStatus;
use App\Models\OrderItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * KdsService memusatkan seluruh logika transisi status persiapan pesanan
 * (Kitchen Display System). Setiap transisi dibungkus lockForUpdate() +
 * DB::transaction() untuk mencegah dua staf dapur "merebut" item yang sama
 * secara bersamaan -- race condition khas lingkungan high-concurrency,
 * pola yang sama seperti TransactionService::createTransaction().
 */
final class KdsService
{
    /**
     * Staf dapur mengambil alih satu item untuk mulai diracik.
     * Hanya item berstatus PENDING yang boleh diklaim.
     */
    public function claim(int $orderItemId, int $staffId): OrderItem
    {
        return DB::transaction(function () use ($orderItemId, $staffId) {
            $item = OrderItem::lockForUpdate()->findOrFail($orderItemId);

            if ($item->preparation_status !== PreparationStatus::Pending) {
                throw ValidationException::withMessages([
                    'kds' => 'Item ini sudah diambil oleh staf lain barusan.',
                ]);
            }

            $item->update([
                'preparation_status' => PreparationStatus::Brewing,
                'processed_by' => $staffId,
            ]);

            return $item;
        });
    }

    /**
     * Menandai item selesai diracik. HANYA staf yang mengklaim item ini
     * (processed_by) yang boleh menyelesaikannya -- mencegah staf lain
     * "mencuri kredit" atas pekerjaan staf lain.
     */
    public function markReady(int $orderItemId, int $staffId): OrderItem
    {
        return DB::transaction(function () use ($orderItemId, $staffId) {
            $item = OrderItem::lockForUpdate()->findOrFail($orderItemId);

            if ($item->preparation_status !== PreparationStatus::Brewing) {
                throw ValidationException::withMessages([
                    'kds' => 'Item ini belum berstatus sedang diracik.',
                ]);
            }

            if ($item->processed_by !== $staffId) {
                throw ValidationException::withMessages([
                    'kds' => 'Anda tidak dapat menyelesaikan item yang diambil staf lain.',
                ]);
            }

            $item->update(['preparation_status' => PreparationStatus::Ready]);

            return $item;
        });
    }

    /**
     * Admin override / self-release: lepaskan kembali item yang "nyangkut"
     * di status BREWING (misal staf lupa logout / shift berakhir), agar bisa
     * diklaim ulang oleh staf lain.
     */
    public function release(int $orderItemId, int $staffId, bool $isAdministrator): OrderItem
    {
        return DB::transaction(function () use ($orderItemId, $staffId, $isAdministrator) {
            $item = OrderItem::lockForUpdate()->findOrFail($orderItemId);

            if (! $isAdministrator && $item->processed_by !== $staffId) {
                throw ValidationException::withMessages([
                    'kds' => 'Hanya Administrator atau staf yang mengambil item ini yang bisa melepaskannya.',
                ]);
            }

            $item->update([
                'preparation_status' => PreparationStatus::Pending,
                'processed_by' => null,
            ]);

            return $item;
        });
    }
}
