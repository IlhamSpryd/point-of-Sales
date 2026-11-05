<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use App\Modules\HR\Models\StatutoryRuleVersion;
use App\Modules\Inventory\Models\IngredientStockBalance;
use App\Modules\Inventory\Models\ModifierIngredient;
use App\Modules\Inventory\Models\ProductIngredient;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Sales\Models\OrderItemModifier;
use Database\Seeders\StatutoryRuleVersionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_liveness_probe_returns_200_ok_without_dependency_checks(): void
    {
        // Bahkan jika DB melempar exception, liveness probe tidak boleh gagal (menghindari restart loop)
        DB::shouldReceive('select')->never();

        $response = $this->getJson('/health/live');

        $response->assertOk();
        $response->assertJson([
            'status' => 'UP',
        ]);
        $this->assertNotEmpty($response->json('timestamp'));
    }

    public function test_readiness_probe_returns_200_when_dependencies_healthy(): void
    {
        $response = $this->getJson('/health/ready');

        $response->assertOk();
        $response->assertJson([
            'status' => 'UP',
            'checks' => [
                'database' => [
                    'status' => 'UP',
                ],
                'cache' => [
                    'status' => 'UP',
                ],
                'queue' => [
                    'status' => 'UP',
                ],
                'storage' => [
                    'status' => 'UP',
                ],
            ],
        ]);
    }

    public function test_readiness_probe_returns_503_when_database_fails_and_sanitizes_sensitive_errors(): void
    {
        DB::shouldReceive('select')
            ->once()
            ->with('SELECT 1')
            ->andThrow(new RuntimeException('SQLSTATE[HY000] [1045] Access denied for user "secret_db_user"@"10.0.0.5" (using password: YES_SUPER_SECRET_PASSWORD)'));

        $response = $this->getJson('/health/ready');

        $response->assertStatus(503);
        $response->assertJson([
            'status' => 'DOWN',
            'checks' => [
                'database' => [
                    'status' => 'DOWN',
                    'message' => 'Database connection unavailable',
                ],
            ],
        ]);

        // Pastikan kredensial rahasia tidak bocor ke payload HTTP
        $rawContent = (string) $response->getContent();
        $this->assertStringNotContainsString('secret_db_user', $rawContent);
        $this->assertStringNotContainsString('YES_SUPER_SECRET_PASSWORD', $rawContent);
    }

    public function test_readiness_probe_returns_503_when_cache_fails(): void
    {
        Cache::shouldReceive('put')
            ->once()
            ->andThrow(new RuntimeException('Redis connection refused at 10.0.0.99:6379 auth=secret_redis_token'));

        $response = $this->getJson('/health/ready');

        $response->assertStatus(503);
        $response->assertJson([
            'status' => 'DOWN',
            'checks' => [
                'cache' => [
                    'status' => 'DOWN',
                    'message' => 'Cache service unavailable',
                ],
            ],
        ]);

        $this->assertStringNotContainsString('secret_redis_token', (string) $response->getContent());
    }

    public function test_api_health_endpoints_respond(): void
    {
        $live = $this->getJson('/api/health/live');
        $live->assertOk();
        $live->assertJson(['status' => 'UP']);

        $ready = $this->getJson('/api/health/ready');
        $ready->assertOk();
        $ready->assertJson(['status' => 'UP']);
    }

    public function test_statutory_rule_versions_seeder_is_idempotent_and_registered_in_database_seeder(): void
    {
        $seederSource = (string) file_get_contents(base_path('database/seeders/DatabaseSeeder.php'));
        $this->assertStringContainsString('StatutoryRuleVersionsSeeder::class', $seederSource);

        // Eksekusi pertama
        $this->seed(StatutoryRuleVersionsSeeder::class);
        $firstCount = StatutoryRuleVersion::count();
        $this->assertGreaterThanOrEqual(6, $firstCount);

        // Eksekusi kedua (idempoten, tidak menduplikasi baris)
        $this->seed(StatutoryRuleVersionsSeeder::class);
        $secondCount = StatutoryRuleVersion::count();
        $this->assertSame($firstCount, $secondCount);
    }

    public function test_p1_05_models_enforce_explicit_fillable_and_reject_hostile_mass_assignment(): void
    {
        $models = [
            ProductIngredient::class,
            ModifierIngredient::class,
            StockBalance::class,
            IngredientStockBalance::class,
            OrderItemModifier::class,
        ];

        foreach ($models as $modelClass) {
            $instance = new $modelClass;

            $this->assertNotEmpty(
                $instance->getFillable(),
                "{$modelClass} wajib mendefinisikan \$fillable secara eksplisit."
            );
            $this->assertSame(
                ['*'],
                $instance->getGuarded(),
                "{$modelClass} tidak boleh menggunakan \$guarded = []."
            );

            $instance->fill([
                'untrusted_injected_field' => 'hacked',
                'is_admin' => true,
                'role_id' => 999,
            ]);

            $this->assertArrayNotHasKey('untrusted_injected_field', $instance->getAttributes());
            $this->assertArrayNotHasKey('is_admin', $instance->getAttributes());
            $this->assertArrayNotHasKey('role_id', $instance->getAttributes());
        }
    }
}
