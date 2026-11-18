<?php

declare(strict_types=1);

namespace Tests\Arch;

use PHPUnit\Framework\TestCase;

/**
 * Architecture Guardrail: No HTTP / Network Calls Inside DB Transactions (ADR-107 / ADR-108)
 * Network I/O (payment gateways, webhooks, external APIs) must NEVER occur inside DB::transaction
 * to prevent connection pool exhaustion and extended row-locking deadlocks.
 */
class NoHttpInTransactionTest extends TestCase
{
    public function test_checkout_order_handler_has_no_http_or_gateway_calls_in_db_transaction(): void
    {
        $basePath = dirname(__DIR__, 2);
        $filePath = $basePath.'/app/Modules/Sales/Application/Handlers/CheckoutOrderHandler.php';
        $this->assertFileExists($filePath);

        $content = (string) file_get_contents($filePath);

        // Assert no direct HTTP facade usage
        $this->assertDoesNotMatchRegularExpression(
            '/\bHttp::/',
            $content,
            'CheckoutOrderHandler must not make direct Http:: calls inside transaction blocks.'
        );

        // Assert no direct PaymentGatewayInterface usage inside CheckoutOrderHandler
        $this->assertDoesNotMatchRegularExpression(
            '/\bPaymentGatewayInterface\b/',
            $content,
            'CheckoutOrderHandler must not call PaymentGatewayInterface directly. Gateway calls belong in OnlinePaymentAndWebhookHandler outside DB locks.'
        );

        // Assert no curl or file_get_contents http calls
        $this->assertDoesNotMatchRegularExpression(
            '/\b(curl_exec|curl_init)\b/',
            $content,
            'CheckoutOrderHandler must not execute cURL calls.'
        );
    }
}
