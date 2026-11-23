<?php

declare(strict_types=1);

namespace App\Exports\Concerns;

trait SanitizesSpreadsheetFormulas
{
    /**
     * Sanitizes a single cell value to prevent Spreadsheet Formula Injection (CSV DDE).
     */
    protected function sanitizeFormula(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        // Keep pure negative or positive numbers intact (e.g. -15000 or -12.50)
        if (is_numeric(trim($value))) {
            return $value;
        }

        // Neutralize formulas starting with =, +, -, @, \t, or \r
        if (preg_match('/^[\=\+\-\@\t\r]/', $value)) {
            return "'".$value;
        }

        return $value;
    }

    /**
     * Sanitizes an entire row array recursively.
     *
     * @param  array<mixed>  $row
     * @return array<mixed>
     */
    protected function sanitizeRow(array $row): array
    {
        $sanitized = [];
        foreach ($row as $key => $value) {
            if (is_array($value)) {
                $sanitized[$key] = $this->sanitizeRow($value);
            } elseif (is_string($value)) {
                $sanitized[$key] = $this->sanitizeFormula($value);
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }
}
