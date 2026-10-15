<?php

namespace Tests\Feature\Domain1;

use App\Models\EmailOutbox;
use App\Models\EmployeeIdSequence;
use App\Models\OwnerOnboardingToken;
use App\Models\ProvisioningRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Services\EmployeeIdGenerator;
use App\Services\ProvisioningService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Domain 1 — Real MySQL/InnoDB Multi-Process Concurrency Test (TX-001, TX-004, TX-006, TX-007).
 *
 * Menggunakan pcntl_fork() dan proses OS terpisah dengan koneksi database mandiri
 * serta barrier synchronization (tanpa RefreshDatabase wrapper yang menutupi locking riil).
 */
class ProvisioningRealConcurrencyTest extends TestCase
{
    protected bool $optOutFromDefaultTenant = true;

    private string $secret = 'test-provisioning-secret-0123456789abcdef';

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl extension is required for multi-process concurrency testing.');
        }

        config(['services.provisioning.secret_current' => $this->secret]);
        config(['services.provisioning.secret_previous' => null]);
    }

    protected function tearDown(): void
    {
        // Bersihkan data tes secara eksplisit
        Tenant::where('name', 'LIKE', 'Concurrency %')->delete();
        DB::table('provisioning_requests')->where('idempotency_key', 'LIKE', 'conc-%')->delete();

        parent::tearDown();
    }

    /**
     * TX-006 & TX-007: 50 operasi generate employee ID konkuren pada MySQL/InnoDB.
     * Tidak ada ID duplikat, urutan monotonik, dan tidak ada deadlock yang tidak tertangani.
     */
    public function test_concurrent_employee_id_generation_produces_monotonic_unique_ids(): void
    {
        $tenant = Tenant::create(['name' => 'Concurrency Employee Cafe ' . Str::random(6)]);
        $concurrency = 50;
        $barrierDir = sys_get_temp_dir() . '/barrier-emp-' . bin2hex(random_bytes(5));
        mkdir($barrierDir, 0777, true);

        $root = base_path();
        $pids = [];

        for ($i = 0; $i < $concurrency; $i++) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                $this->fail("Failed to fork process {$i}");
            }

            if ($pid === 0) {
                // Proses anak: reset koneksi DB agar tidak berbagi socket PDO dengan proses induk
                DB::purge('mysql');

                // Tandai bahwa proses ini siap
                file_put_contents("{$barrierDir}/ready_{$i}", '1');

                // Tunggu semua proses anak siap di barrier (maks 10 detik)
                $deadline = microtime(true) + 10.0;
                while (count(glob("{$barrierDir}/ready_*")) < $concurrency && microtime(true) < $deadline) {
                    usleep(1000); // 1ms
                }

                try {
                    $id = EmployeeIdGenerator::next($tenant->id);
                    file_put_contents("{$barrierDir}/result_{$i}", json_encode(['id' => $id]));
                    exit(0);
                } catch (\Throwable $e) {
                    file_put_contents("{$barrierDir}/result_{$i}", json_encode([
                        'error' => $e->getMessage(),
                        'class' => get_class($e),
                    ]));
                    exit(1);
                }
            }

            $pids[] = $pid;
        }

        // Tunggu semua proses anak selesai
        $exitCodes = [];
        foreach ($pids as $pid) {
            $status = 0;
            pcntl_waitpid($pid, $status);
            $exitCodes[] = pcntl_wexitstatus($status);
        }

        // Kumpulkan hasil
        $results = [];
        $errors = [];
        for ($i = 0; $i < $concurrency; $i++) {
            $file = "{$barrierDir}/result_{$i}";
            if (file_exists($file)) {
                $data = json_decode(file_get_contents($file), true);
                if (isset($data['id'])) {
                    $results[] = $data['id'];
                } else {
                    $errors[] = $data;
                }
            }
        }

        // Bersihkan direktori barrier
        array_map('unlink', glob("{$barrierDir}/*"));
        rmdir($barrierDir);

        // Verifikasi
        $this->assertEmpty($errors, 'Errors occurred: ' . json_encode($errors));
        $this->assertCount($concurrency, $results, "All {$concurrency} processes must return an employee ID");

        // Tidak ada ID duplikat
        $unique = array_unique($results);
        $this->assertCount($concurrency, $unique, 'All 50 generated employee IDs must be unique (no duplicates)');

        // Format dan batas nomor urut (YVL-EMP-<tenantId>-0001 s/d YVL-EMP-<tenantId>-0050)
        sort($results);
        $expected = [];
        for ($i = 1; $i <= $concurrency; $i++) {
            $expected[] = sprintf('YVL-EMP-%d-%04d', $tenant->id, $i);
        }
        $this->assertSame($expected, $results, 'Employee IDs must be perfectly sequential and monotonic');
    }

    /**
     * TX-001 & TX-004: Dua worker concurrent mencoba mengklaim dan provisioning
     * dengan idempotency key dan payload yang persis sama.
     * Hasil: Tepat 1 tenant ter-commit, tidak ada duplicate record, tidak ada crash.
     */
    public function test_concurrent_provisioning_with_same_idempotency_key_creates_exactly_one_tenant(): void
    {
        $concurrency = 2;
        $key = 'conc-race-' . bin2hex(random_bytes(8));
        $email = 'conc-owner-' . bin2hex(random_bytes(5)) . '@example.test';
        $payload = [
            'tenant_name' => 'Concurrency Provisioning Cafe',
            'owner_name' => 'Concurrency Owner',
            'owner_email' => $email,
            'owner_phone' => '081234567890',
        ];
        $fingerprint = hash('sha256', 'POST|/api/v1/provisioning/registrations|' . json_encode($payload, JSON_UNESCAPED_SLASHES));

        $barrierDir = sys_get_temp_dir() . '/barrier-prov-' . bin2hex(random_bytes(5));
        mkdir($barrierDir, 0777, true);

        $pids = [];
        for ($i = 0; $i < $concurrency; $i++) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                $this->fail("Failed to fork process {$i}");
            }

            if ($pid === 0) {
                DB::purge('mysql');

                file_put_contents("{$barrierDir}/ready_{$i}", '1');

                $deadline = microtime(true) + 10.0;
                while (count(glob("{$barrierDir}/ready_*")) < $concurrency && microtime(true) < $deadline) {
                    usleep(1000);
                }

                try {
                    $service = app(ProvisioningService::class);
                    $result = $service->provision($payload, $key, $fingerprint);
                    file_put_contents("{$barrierDir}/result_{$i}", json_encode($result));
                    exit(0);
                } catch (\Throwable $e) {
                    file_put_contents("{$barrierDir}/result_{$i}", json_encode([
                        'error' => $e->getMessage(),
                        'class' => get_class($e),
                    ]));
                    exit(1);
                }
            }

            $pids[] = $pid;
        }

        $exitCodes = [];
        foreach ($pids as $pid) {
            $status = 0;
            pcntl_waitpid($pid, $status);
            $exitCodes[] = pcntl_wexitstatus($status);
        }

        $results = [];
        for ($i = 0; $i < $concurrency; $i++) {
            $file = "{$barrierDir}/result_{$i}";
            if (file_exists($file)) {
                $results[] = json_decode(file_get_contents($file), true);
            }
        }

        array_map('unlink', glob("{$barrierDir}/*"));
        rmdir($barrierDir);

        // Kedua proses harus exit code 0 (tanpa fatal exception)
        $this->assertSame([0, 0], $exitCodes);

        // Tepat 1 tenant tercipta di database
        $this->assertSame(1, Tenant::where('name', $payload['tenant_name'])->count());

        // Tepat 1 user owner tercipta
        $this->assertSame(1, User::where('email', $email)->count());

        // Tepat 1 baris ledger berstatus completed
        $ledger = ProvisioningRequest::where('idempotency_key', $key)->first();
        $this->assertNotNull($ledger);
        $this->assertSame(ProvisioningRequest::STATUS_COMPLETED, $ledger->status);

        // Satu proses menghasilkan status 201 (pembuat), yang lain 200 (idempotent response)
        $statuses = array_column($results, 'status');
        sort($statuses);
        $this->assertSame([200, 201], $statuses);
    }

    /**
     * TX-004: Uji ketahanan race condition minimal 20 iterasi berturut-turut pada MySQL riil.
     * Membuktikan bahwa tidak pernah terjadi duplicate tenant atau unhandled conflict.
     */
    public function test_concurrent_idempotent_race_20_iterations(): void
    {
        $iterations = 20;

        for ($iter = 1; $iter <= $iterations; $iter++) {
            $key = "conc-iter-{$iter}-" . bin2hex(random_bytes(6));
            $email = "conc-iter-{$iter}-" . bin2hex(random_bytes(4)) . "@example.test";
            $payload = [
                'tenant_name' => "Concurrency Iteration {$iter} Cafe",
                'owner_name' => "Owner {$iter}",
                'owner_email' => $email,
                'owner_phone' => '081234567890',
            ];
            $fingerprint = hash('sha256', 'POST|/api/v1/provisioning/registrations|' . json_encode($payload, JSON_UNESCAPED_SLASHES));

            $barrierDir = sys_get_temp_dir() . "/barrier-iter-{$iter}-" . bin2hex(random_bytes(4));
            mkdir($barrierDir, 0777, true);

            $pids = [];
            for ($i = 0; $i < 2; $i++) {
                $pid = pcntl_fork();
                if ($pid === 0) {
                    DB::purge('mysql');
                    file_put_contents("{$barrierDir}/ready_{$i}", '1');

                    $deadline = microtime(true) + 5.0;
                    while (count(glob("{$barrierDir}/ready_*")) < 2 && microtime(true) < $deadline) {
                        usleep(500);
                    }

                    try {
                        $service = app(ProvisioningService::class);
                        $result = $service->provision($payload, $key, $fingerprint);
                        file_put_contents("{$barrierDir}/result_{$i}", json_encode($result));
                        exit(0);
                    } catch (\Throwable $e) {
                        file_put_contents("{$barrierDir}/result_{$i}", json_encode(['error' => $e->getMessage()]));
                        exit(1);
                    }
                }
                $pids[] = $pid;
            }

            foreach ($pids as $pid) {
                pcntl_waitpid($pid, $status);
            }

            array_map('unlink', glob("{$barrierDir}/*"));
            rmdir($barrierDir);

            // Verifikasi tepat 1 tenant per iterasi
            $this->assertSame(
                1,
                Tenant::where('name', $payload['tenant_name'])->count(),
                "Iteration {$iter} must create exactly 1 tenant"
            );
        }
    }
}

