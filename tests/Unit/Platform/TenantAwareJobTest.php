<?php

declare(strict_types=1);

namespace Tests\Unit\Platform;

use App\Core\Context\TenantContext;
use App\Platform\Tenancy\TenantAwareJob;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class TenantAwareJobTest extends TestCase
{
    private TenantContext $context;

    protected function setUp(): void
    {
        parent::setUp();
        $this->context = new TenantContext;
    }

    public function test_rejects_zero_or_negative_tenant_id(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('TenantAwareJob requires a positive tenant ID');

        new class(0) extends TenantAwareJob
        {
            protected function execute(): void {}
        };
    }

    public function test_rejects_negative_tenant_id(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('TenantAwareJob requires a positive tenant ID');

        new class(-5) extends TenantAwareJob
        {
            protected function execute(): void {}
        };
    }

    public function test_job_restores_tenant_context_during_and_after_execution(): void
    {
        $this->context->setTenantId(1);
        $this->context->setCompanyId(10);
        $this->context->setStoreId(100);

        $executed = false;
        $capturedTenantId = null;
        $capturedCompanyId = null;
        $capturedStoreId = null;

        $job = new class(42, 200, 300, 999, function (TenantContext $ctx) use (&$executed, &$capturedTenantId, &$capturedCompanyId, &$capturedStoreId) {
            $executed = true;
            $capturedTenantId = $ctx->getTenantId();
            $capturedCompanyId = $ctx->getCompanyId();
            $capturedStoreId = $ctx->getStoreId();
        }, $this->context) extends TenantAwareJob {

            public function __construct(
                int $tenantId,
                ?int $companyId,
                ?int $branchId,
                ?int $actorId,
                private readonly \Closure $callback,
                private readonly TenantContext $tenantContext
            ) {
                parent::__construct($tenantId, $companyId, $branchId, $actorId);
            }

            protected function execute(): void
            {
                ($this->callback)($this->tenantContext);
            }
        };

        $job->handle($this->context);

        $this->assertTrue($executed);
        $this->assertSame(42, $capturedTenantId);
        $this->assertSame(200, $capturedCompanyId);
        $this->assertSame(300, $capturedStoreId);

        // Verify restoration of previous context
        $this->assertSame(1, $this->context->getTenantId());
        $this->assertSame(10, $this->context->getCompanyId());
        $this->assertSame(100, $this->context->getStoreId());
    }

    public function test_job_restores_context_even_when_execute_fails(): void
    {
        $this->context->setTenantId(5);
        $this->context->setCompanyId(50);
        $this->context->setStoreId(500);

        $job = new class(99, 990, 9900, null) extends TenantAwareJob
        {
            protected function execute(): void
            {
                throw new RuntimeException('Job execution failed intentionally.');
            }
        };

        try {
            $job->handle($this->context);
            $this->fail('Expected exception was not thrown.');
        } catch (RuntimeException $e) {
            $this->assertSame('Job execution failed intentionally.', $e->getMessage());
        }

        // Context must be restored
        $this->assertSame(5, $this->context->getTenantId());
        $this->assertSame(50, $this->context->getCompanyId());
        $this->assertSame(500, $this->context->getStoreId());
    }

    public function test_job_handle_without_arguments_resolves_context_automatically(): void
    {
        $executed = false;
        $job = new class(77, 770, 7700, 1, function () use (&$executed) {
            $executed = true;
        }) extends TenantAwareJob {

            public function __construct(
                int $tenantId,
                ?int $companyId,
                ?int $branchId,
                ?int $actorId,
                private readonly \Closure $callback
            ) {
                parent::__construct($tenantId, $companyId, $branchId, $actorId);
            }

            protected function execute(): void
            {
                ($this->callback)();
            }
        };

        $job->handle();
        $this->assertTrue($executed);
    }
}
