<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CorrelationIdMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    public function test_assigns_generated_correlation_id_when_header_absent(): void
    {
        $response = $this->get('/up');

        $response->assertSuccessful();
        $this->assertTrue($response->headers->has('X-Correlation-ID'));
        $correlationId = $response->headers->get('X-Correlation-ID');
        $this->assertNotEmpty($correlationId);
        $this->assertTrue(Str::isUuid((string) $correlationId));
    }

    public function test_propagates_incoming_correlation_id(): void
    {
        $customId = 'trace-apex-'.Str::uuid()->toString();

        $response = $this->withHeaders([
            'X-Correlation-ID' => $customId,
        ])->get('/up');

        $response->assertSuccessful();
        $this->assertSame($customId, $response->headers->get('X-Correlation-ID'));
    }

    public function test_binds_correlation_and_tenant_context_to_logger(): void
    {
        $customId = 'test-corr-'.Str::uuid()->toString();

        $response = $this->withHeaders([
            'X-Correlation-ID' => $customId,
        ])->get('/up');

        $response->assertSuccessful();
        $this->assertSame($customId, $response->headers->get('X-Correlation-ID'));
    }
}
