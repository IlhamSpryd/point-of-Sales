<?php

declare(strict_types=1);

namespace Tests\Arch;

use PHPUnit\Framework\TestCase;

/**
 * Architecture Guardrail: Financial Precision & Anti-Float Money Policy (DEC-001 / ADR-106)
 * Enforces that public methods in Finance Services must not accept raw float types for monetary parameters.
 * Monetary amounts must be handled using exact decimal strings (BCMath) or integer minor units.
 */
class NoFloatMoneySignatureTest extends TestCase
{
    /**
     * Parameter substrings that indicate monetary amounts.
     *
     * @var list<string>
     */
    private const MONETARY_KEYWORDS = [
        'amount',
        'money',
        'price',
        'balance',
        'debit',
        'credit',
        'total',
        'fee',
        'cost',
        'nominal',
        'subtotal',
    ];

    public function test_finance_services_do_not_accept_raw_float_for_monetary_parameters(): void
    {
        $basePath = dirname(__DIR__, 2);
        $financeServicesDir = $basePath.'/app/Modules/Finance/Services';
        $this->assertDirectoryExists($financeServicesDir);

        $violations = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($financeServicesDir));

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $content = file_get_contents($file->getRealPath());
            if ($content === false) {
                continue;
            }

            $relPath = str_replace($basePath.'/', '', $file->getRealPath());

            // Match public methods
            if (preg_match_all('/public\s+function\s+([A-Za-z0-9_]+)\s*\(([^)]*)\)/', $content, $methodMatches, PREG_SET_ORDER)) {
                foreach ($methodMatches as $match) {
                    $methodName = $match[1];
                    $paramsList = $match[2];

                    if (trim($paramsList) === '') {
                        continue;
                    }

                    // Parse individual parameters
                    $params = explode(',', $paramsList);
                    foreach ($params as $param) {
                        $param = trim($param);
                        // Check if strictly typed as "float $param" (excluding union types like float|string)
                        if (preg_match('/^float\s+\$([A-Za-z0-9_]+)/', $param, $typeMatch)) {
                            $varName = strtolower($typeMatch[1]);
                            foreach (self::MONETARY_KEYWORDS as $keyword) {
                                if (str_contains($varName, $keyword)) {
                                    $violations[] = "{$relPath}::{$methodName} accepts raw float parameter: '{$param}'. Use string (BCMath) or int (minor units) per DEC-001.";
                                    break;
                                }
                            }
                        }
                    }
                }
            }
        }

        $this->assertEmpty(
            $violations,
            "Raw float monetary parameters detected in Finance Services:\n".implode("\n", $violations)
        );
    }
}
