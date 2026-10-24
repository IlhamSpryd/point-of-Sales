<?php

declare(strict_types=1);

namespace App\Support\Dataset\Generators\Ekstensi\Concerns;

use App\Support\Dataset\SeededRandom;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Helper bersama untuk generator ekstensi (tabel yang belum dicakup
 * generator inti). Semua referensi di-resolve dari data nyata (bukan ID
 * hardcode) sehingga aman dijalankan ulang dan lintas tenant.
 */
trait ResolvesDatasetContext
{
    /** @return array<int,int> store_id list milik tenant */
    private function storeIds(int $tenantId): array
    {
        return DB::table('stores')->where('tenant_id', $tenantId)->whereNull('deleted_at')
            ->orderBy('id')->pluck('id')->map(fn ($v) => (int) $v)->all();
    }

    /** @return array<int,int> user_id list milik tenant */
    private function userIds(int $tenantId): array
    {
        return DB::table('users')->where('tenant_id', $tenantId)->whereNull('deleted_at')
            ->orderBy('id')->pluck('id')->map(fn ($v) => (int) $v)->all();
    }

    /** User tertentu berdasarkan role_code (fallback: user pertama). */
    private function userWithRole(int $tenantId, string $roleCode, int $fallback): int
    {
        $id = DB::table('users as u')
            ->join('roles as r', function ($join) {
                $join->on('r.id', '=', 'u.role_id')->on('r.tenant_id', '=', 'u.tenant_id');
            })
            ->where('u.tenant_id', $tenantId)
            ->where('r.role_code', $roleCode)
            ->whereNull('u.deleted_at')
            ->orderBy('u.id')
            ->value('u.id');

        return (int) ($id ?? $fallback);
    }

    /**
     * User per cabang dengan role tertentu.
     *
     * @return array<int,int> store_id => user_id
     */
    private function usersByStore(int $tenantId, string $roleCode, int $fallback, ?int $storeId = null): array
    {
        $rows = DB::table('users as u')
            ->join('roles as r', function ($join) {
                $join->on('r.id', '=', 'u.role_id')->on('r.tenant_id', '=', 'u.tenant_id');
            })
            ->join('user_stores as us', function ($join) {
                $join->on('us.user_id', '=', 'u.id')->on('us.tenant_id', '=', 'u.tenant_id');
            })
            ->where('u.tenant_id', $tenantId)
            ->where('r.role_code', $roleCode)
            ->whereNull('u.deleted_at')
            ->when($storeId !== null, fn ($q) => $q->where('us.store_id', $storeId))
            ->orderBy('u.id')
            ->get(['us.store_id', 'u.id']);

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row->store_id] ??= (int) $row->id;
        }

        foreach ($this->storeIds($tenantId) as $sid) {
            $map[$sid] ??= $fallback;
        }

        return $map;
    }

    /** @return array<int, array{id:int,code:string}> */
    private function storesWithCode(int $tenantId): array
    {
        return DB::table('stores')->where('tenant_id', $tenantId)->whereNull('deleted_at')
            ->orderBy('id')->get(['id', 'code'])->map(fn ($r) => ['id' => (int) $r->id, 'code' => (string) $r->code])->all();
    }

    /** @return array<int,int> device id milik store (urut id) */
    private function deviceIds(int $tenantId, int $storeId): array
    {
        return DB::table('devices')->where('tenant_id', $tenantId)->where('store_id', $storeId)
            ->orderBy('id')->pluck('id')->map(fn ($v) => (int) $v)->all();
    }

    /**
     * Hari pertama bulan DARI tanggal tersebut pada jam 00:00.
     *
     * CarbonImmutable::startOfMonth() underflow bila tanggal sudah hari ke-1
     * (1 Nov -> 1 Okt), sehingga eksplisit diset ke tanggal 1.
     */
    private function monthStart(CarbonImmutable $date): CarbonImmutable
    {
        return $date->setDate($date->year, $date->month, 1)->setTime(0, 0);
    }

    private function rngFor(int $tenantId, string $domain): SeededRandom
    {
        return (new SeededRandom($this->config->seed))->derive("ekstensi:{$domain}:{$tenantId}");
    }

    /**
     * Tanggal acak dalam jendela dataset.
     */
    private function randomDay(SeededRandom $rng, ?CarbonImmutable $from = null, ?CarbonImmutable $to = null, bool $businessHours = true): CarbonImmutable
    {
        $from ??= $this->config->startDate;
        $to ??= $this->config->effectiveEndDate();
        $span = max(0, (int) $from->startOfDay()->diffInDays($to->startOfDay()));
        $day = $from->startOfDay()->addDays($rng->int(0, $span));

        if (! $businessHours) {
            return $day;
        }

        return $day->setTime($rng->int(8, 21), $rng->int(0, 59), $rng->int(0, 59));
    }

    /** Hari pertama bulan berikutnya (tanpa overflow tanggal 31). */
    private function nextMonthStart(CarbonImmutable $date): CarbonImmutable
    {
        return $date->addMonthNoOverflow()->setDate($date->addMonthNoOverflow()->year, $date->addMonthNoOverflow()->month, 1)->setTime(0, 0);
    }

    /** Apakah tabel pernah terisi untuk tenant ini? */
    private function tenantHasRows(string $table, int $tenantId): bool
    {
        return DB::table($table)->where('tenant_id', $tenantId)->exists();
    }
}
