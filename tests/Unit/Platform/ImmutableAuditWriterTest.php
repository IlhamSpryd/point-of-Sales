<?php

declare(strict_types=1);

namespace Tests\Unit\Platform;

use App\Platform\Audit\ImmutableAuditWriter;
use PHPUnit\Framework\TestCase;

class ImmutableAuditWriterTest extends TestCase
{
    private ImmutableAuditWriter $writer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->writer = new ImmutableAuditWriter;
    }

    public function test_redacts_sensitive_keys_automatically(): void
    {
        $payload = [
            'user_id' => 123,
            'email' => 'user@example.com',
            'password' => 'secret12345',
            'pin' => '9988',
            'token' => 'bearer-token-abc',
            'client_secret' => 'super-secret-key',
            'npwp' => '01.234.567.8-901.000',
            'bank_account_number' => '1234567890',
        ];

        $redacted = $this->writer->redactSensitiveData($payload);

        $this->assertSame(123, $redacted['user_id']);
        $this->assertSame('user@example.com', $redacted['email']);
        $this->assertSame('[REDACTED]', $redacted['password']);
        $this->assertSame('[REDACTED]', $redacted['pin']);
        $this->assertSame('[REDACTED]', $redacted['token']);
        $this->assertSame('[REDACTED]', $redacted['client_secret']);
        $this->assertSame('[REDACTED]', $redacted['npwp']);
        $this->assertSame('[REDACTED]', $redacted['bank_account_number']);
    }

    public function test_preserves_non_sensitive_counter_and_timestamp_metadata(): void
    {
        $payload = [
            'token_count' => 5,
            'pin_length' => 6,
            'token_expires_at' => '2026-12-31T23:59:59Z',
            'session_expires_in' => 3600,
        ];

        $redacted = $this->writer->redactSensitiveData($payload);

        $this->assertSame(5, $redacted['token_count']);
        $this->assertSame(6, $redacted['pin_length']);
        $this->assertSame('2026-12-31T23:59:59Z', $redacted['token_expires_at']);
        $this->assertSame(3600, $redacted['session_expires_in']);
    }

    public function test_recursively_redacts_nested_arrays(): void
    {
        $payload = [
            'tenant' => [
                'name' => 'Toko Retail',
                'npwp' => '12.345.678.9-001.000',
                'bank_details' => [
                    'bank_name' => 'BCA',
                    'bank_account_number' => '88887777',
                ],
            ],
        ];

        $redacted = $this->writer->redactSensitiveData($payload);

        $this->assertSame('Toko Retail', $redacted['tenant']['name']);
        $this->assertSame('[REDACTED]', $redacted['tenant']['npwp']);
        $this->assertSame('BCA', $redacted['tenant']['bank_details']['bank_name']);
        $this->assertSame('[REDACTED]', $redacted['tenant']['bank_details']['bank_account_number']);
    }

    public function test_is_sensitive_key_detection(): void
    {
        $this->assertTrue($this->writer->isSensitiveKey('password'));
        $this->assertTrue($this->writer->isSensitiveKey('admin_password'));
        $this->assertTrue($this->writer->isSensitiveKey('api_token'));
        $this->assertTrue($this->writer->isSensitiveKey('card_cvv'));
        $this->assertTrue($this->writer->isSensitiveKey('npwp'));
        $this->assertTrue($this->writer->isSensitiveKey('bank_account_number'));

        $this->assertFalse($this->writer->isSensitiveKey('username'));
        $this->assertFalse($this->writer->isSensitiveKey('total_amount'));
        $this->assertFalse($this->writer->isSensitiveKey('token_count'));
    }
}
