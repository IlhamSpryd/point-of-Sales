<?php

declare(strict_types=1);

namespace Tests\Feature\Domain2;

use App\Jobs\ProcessProvisioningOutboxJob;
use App\Models\EmailOutbox;
use App\Models\Ingredient;
use App\Models\IngredientRestockForecast;
use App\Models\Tenant;
use App\Services\Context\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Domain 2: Console Commands Tenant Context & Fail-Closed Safety Tests.
 *
 * Menguji bahwa background CLI/Scheduler commands (ProcessEmailOutbox & RecomputeRestockForecasts)
 * mengeksekusi records lintas-tenant secara aman di bawah fail-closed scopes
 * dan selalu membersihkan konteks (try-finally).
 */
class ConsoleCommandsTenantContextTest extends TestCase
{
    use RefreshDatabase;

    protected bool $optOutFromDefaultTenant = true;

    private Tenant $tenantA;

    private Tenant $tenantB;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $context = app(TenantContext::class);
        $context->clear();

        $this->tenantA = Tenant::create(['name' => 'Console Tenant A '.Str::random(6), 'readiness_status' => 'active']);
        $this->tenantB = Tenant::create(['name' => 'Console Tenant B '.Str::random(6), 'readiness_status' => 'active']);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();
        parent::tearDown();
    }

    /**
     * Test 1: ProcessEmailOutbox memproses email lintas-tenant dan membersihkan konteks.
     */
    public function test_process_email_outbox_processes_cross_tenant_records_safely(): void
    {
        Queue::fake();

        $context = app(TenantContext::class);
        $context->clear();

        // Masukkan outbox record untuk Tenant A dan Tenant B
        $outboxA = $context->runWithoutTenant(function () {
            return EmailOutbox::create([
                'tenant_id' => $this->tenantA->id,
                'event_type' => 'owner_welcome',
                'recipient_email' => 'ownerA@test.local',
                'subject' => 'Welcome Tenant A',
                'body_text' => 'Hello Tenant A',
                'status' => EmailOutbox::STATUS_PENDING,
                'next_attempt_at' => now()->subMinute(),
            ]);
        });

        $outboxB = $context->runWithoutTenant(function () {
            return EmailOutbox::create([
                'tenant_id' => $this->tenantB->id,
                'event_type' => 'owner_welcome',
                'recipient_email' => 'ownerB@test.local',
                'subject' => 'Welcome Tenant B',
                'body_text' => 'Hello Tenant B',
                'status' => EmailOutbox::STATUS_PENDING,
                'next_attempt_at' => now()->subMinute(),
            ]);
        });

        // Pastikan TenantContext kosong (fail-closed) sebelum command jalan
        $this->assertNull($context->getTenantId());

        $exitCode = Artisan::call('emails:process-outbox');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Dispatched 2 outbox email(s)', $output);

        // Buktikan job di-dispatch untuk kedua outbox lintas tenant
        Queue::assertPushed(ProcessProvisioningOutboxJob::class, function ($job) use ($outboxA) {
            return $job->outboxId === $outboxA->id;
        });

        Queue::assertPushed(ProcessProvisioningOutboxJob::class, function ($job) use ($outboxB) {
            return $job->outboxId === $outboxB->id;
        });

        // Konteks harus tetap null setelah command selesai
        $this->assertNull($context->getTenantId(), 'TenantContext harus bersih setelah command selesai');
    }

    /**
     * Test 2: RecomputeRestockForecasts mengiterasi per-tenant dan merestore konteks di finally block.
     */
    public function test_recompute_restock_forecasts_iterates_tenants_and_restores_context(): void
    {
        $context = app(TenantContext::class);
        $context->clear();

        // Buat bahan baku di Tenant A
        $ingredientA = $context->runAs($this->tenantA->id, null, function () {
            return Ingredient::create([
                'tenant_id' => $this->tenantA->id,
                'name' => 'Bahan Baku A',
                'unit' => 'g',
                'current_stock' => 1000,
                'cost_per_unit' => 50,
                'reorder_level' => 100,
            ]);
        });

        // Buat bahan baku di Tenant B
        $ingredientB = $context->runAs($this->tenantB->id, null, function () {
            return Ingredient::create([
                'tenant_id' => $this->tenantB->id,
                'name' => 'Bahan Baku B',
                'unit' => 'ml',
                'current_stock' => 500,
                'cost_per_unit' => 20,
                'reorder_level' => 50,
            ]);
        });

        // 1. Jalankan tanpa konteks awal -> command selesai dan konteks kembali bersih
        $exitCode = Artisan::call('analytics:recompute-restock-forecasts');
        $this->assertSame(0, $exitCode);

        // Verifikasi forecast dibuat untuk bahan baku Tenant A dan Tenant B
        $this->assertDatabaseHas('ingredient_restock_forecasts', [
            'tenant_id' => $this->tenantA->id,
            'ingredient_id' => $ingredientA->id,
        ]);

        $this->assertDatabaseHas('ingredient_restock_forecasts', [
            'tenant_id' => $this->tenantB->id,
            'ingredient_id' => $ingredientB->id,
        ]);

        $this->assertNull($context->getTenantId(), 'TenantContext harus null saat dimulai tanpa konteks');

        // 2. Jalankan dengan konteks awal tersimpan -> command harus merestore konteks awal di finally
        $context->setTenantId($this->tenantA->id);
        $exitCodeWithContext = Artisan::call('analytics:recompute-restock-forecasts');
        $this->assertSame(0, $exitCodeWithContext);

        $this->assertSame($this->tenantA->id, $context->getTenantId(), 'TenantContext harus direstore ke Tenant A di finally block');
    }
}
