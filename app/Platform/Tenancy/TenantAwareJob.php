<?php

declare(strict_types=1);

namespace App\Platform\Tenancy;

use App\Core\Context\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use InvalidArgumentException;

/**
 * Base abstract job providing fail-closed multi-tenant execution context (OPS-01, ADR-104).
 * Ensures tenant isolation is restored on workers before business logic runs.
 */
abstract class TenantAwareJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly int $tenantId,
        public readonly ?int $companyId = null,
        public readonly ?int $branchId = null,
        public readonly ?int $actorId = null,
    ) {
        if ($this->tenantId <= 0) {
            throw new InvalidArgumentException("TenantAwareJob requires a positive tenant ID. Given: {$this->tenantId}");
        }
    }

    /**
     * Executes the job inside the tenant context, restoring state after completion.
     */
    final public function handle(?TenantContext $tenantContext = null): void
    {
        if ($this->tenantId <= 0) {
            throw new InvalidArgumentException("TenantAwareJob requires a positive tenant ID. Given: {$this->tenantId}");
        }

        $context = $tenantContext ?? app(TenantContext::class);

        $context->runAs(
            $this->tenantId,
            $this->branchId,
            fn () => $this->execute(),
            $this->companyId
        );
    }

    /**
     * Abstract execution method where concrete job logic resides.
     */
    abstract protected function execute(): void;
}
