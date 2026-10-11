<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Domain 1 (DB-001 / XD-03) — alokasi `users.employee_id` yang ATOMIK.
 *
 * Menggantikan strategi `max(id) + 1` yang dipakai bersama oleh
 * `User::booted()` dan `ProvisioningService`. Kegagalan strategi lama:
 *  - `max(id)+1` adalah pola read-then-write tanpa penguncian; dua request
 *    konkuren membaca max yang sama dan menghasilkan employee_id yang sama,
 *    lalu INSERT kedua gagal di unique index.
 *  - Nilai juga salah karena `id` auto-increment BUKAN bagian dari nilai
 *    yang diterbitkan — `YVL-EMP-<tahun>-<maxId+1>` tidak menyimpan nomor
 *    urut yang benar-benar dialokasikan, sehingga tabrakan tidak dapat
 *    dideteksi secara lokal.
 *
 * Desain tabel:
 *  - satu baris per (tenant_id, prefix) — `prefix` disimpan agar format
 *    identifier tercatat sebagai data, bukan asumsi kode;
 *  - `last_value` hanya boleh naik, dinaikkan lewat `UPDATE ... SET
 *    last_value = LAST_INSERT_ID(last_value + 1)`;
 *  - backfill memakai nilai prefix angka terbesar yang SUDAH terbit, jadi
 *    nomor berikutnya tidak pernah menabrak baris yang sudah ada.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('employee_id_sequences')) {
            Schema::create('employee_id_sequences', function (Blueprint $table) {
                $table->unsignedBigInteger('tenant_id');
                $table->string('prefix', 32)->default('YVL-EMP');
                $table->unsignedBigInteger('last_value')->default(0);
                $table->timestamps();
                $table->primary(['tenant_id', 'prefix']);
            });
        }

        // Backfill: nilai awal = nomor urut terbesar yang sudah terbit untuk
        // tenant tersebut. Tanpa backfill, tenant lama akan memulai dari 1
        // dan menabrak employee_id yang sudah ada di baris lain.
        $tenants = DB::table('users')->select('tenant_id')->whereNotNull('tenant_id')->distinct()->pluck('tenant_id');
        foreach ($tenants as $tenantId) {
            $maxUsed = $this->maxIssuedSequence((int) $tenantId);

            DB::table('employee_id_sequences')->insertOrIgnore([
                'tenant_id' => $tenantId,
                'prefix' => 'YVL-EMP',
                'last_value' => $maxUsed,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_id_sequences');
    }

    /**
     * Nomor urut terbesar yang sudah terbit pada tenant, dihitung dari
     * segmen numerik terakhir `employee_id` (format lama maupun baru).
     *
     * Sengaja memakai segmen TERAKHIR, bukan `substring_index(-2)`: format
     * lama `YVL-EMP-2026-0004` memakai segmen kedua-dari-akhir sebagai
     * tahun, sedangkan format baru `YVL-EMP-<tenant>-0004` memakainya
     * sebagai tenant. Segmen terakhir stabil pada keduanya.
     */
    private function maxIssuedSequence(int $tenantId): int
    {
        $ids = DB::table('users')
            ->where('tenant_id', $tenantId)
            ->whereNotNull('employee_id')
            ->where('employee_id', '<>', '')
            ->pluck('employee_id');

        $max = 0;
        foreach ($ids as $employeeId) {
            $tail = substr((string) $employeeId, (int) strrpos((string) $employeeId, '-') + 1);
            if ($tail !== '' && ctype_digit($tail)) {
                $max = max($max, (int) $tail);
            }
        }

        return $max;
    }
};
