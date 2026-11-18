<?php

declare(strict_types=1);

namespace App\Platform\Money;

use InvalidArgumentException;
use JsonSerializable;
use Stringable;

/**
 * Immutable Money Value Object (ADR-106 / STR-10)
 *
 * Represents a monetary amount in minor units (e.g. cents/sen) with currency code.
 * All arithmetic operations use deterministic BCMath calculations.
 */
final readonly class Money implements JsonSerializable, Stringable
{
    public function __construct(
        public int $minor,
        public string $currency = 'IDR'
    ) {
        $cleanCurrency = strtoupper(trim($this->currency));
        if ($cleanCurrency === '') {
            throw new InvalidArgumentException('Currency code cannot be empty.');
        }
    }

    /**
     * Create Money representing a whole Indonesian Rupiah amount.
     * With standard 2-decimal financial precision (decimal:2), 1 IDR = 100 minor units.
     */
    public static function idr(int $rupiah): self
    {
        return new self($rupiah * 100, 'IDR');
    }

    /**
     * Create Money from a decimal string, integer, or float representation.
     */
    public static function fromDecimal(string|int|float $amount, string $currency = 'IDR', int $scale = 2): self
    {
        $currency = strtoupper(trim($currency));
        $strAmount = is_float($amount) ? sprintf('%.'.$scale.'f', $amount) : trim((string) $amount);
        $multiplier = (string) (10 ** $scale);
        $minorScaled = bcmul($strAmount, $multiplier, 4);
        $minor = (int) round((float) $minorScaled, 0, PHP_ROUND_HALF_UP);

        return new self($minor, $currency);
    }

    /**
     * Create zero money for the given currency.
     */
    public static function zero(string $currency = 'IDR'): self
    {
        return new self(0, strtoupper(trim($currency)));
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minor + $other->minor, $this->currency);
    }

    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minor - $other->minor, $this->currency);
    }

    public function multiply(string|int|float $factor): self
    {
        $strFactor = trim((string) $factor);
        $product = bcmul((string) $this->minor, $strFactor, 4);
        $rounded = (int) round((float) $product, 0, PHP_ROUND_HALF_UP);

        return new self($rounded, $this->currency);
    }

    /**
     * Allocate money into $parts without losing any remainder (penny/rupiah safe).
     *
     * @return array<int, self>
     */
    public function allocate(int $parts): array
    {
        if ($parts <= 0) {
            throw new InvalidArgumentException('Parts count must be greater than zero.');
        }

        $base = intdiv($this->minor, $parts);
        $remainder = $this->minor % $parts;
        $sign = $remainder < 0 ? -1 : 1;
        $absRemainder = abs($remainder);

        $result = [];
        for ($i = 0; $i < $parts; $i++) {
            $share = $base + ($i < $absRemainder ? $sign : 0);
            $result[] = new self($share, $this->currency);
        }

        return $result;
    }

    public function equals(self $other): bool
    {
        return $this->minor === $other->minor && $this->currency === $other->currency;
    }

    public function greaterThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->minor > $other->minor;
    }

    public function lessThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->minor < $other->minor;
    }

    public function isNegative(): bool
    {
        return $this->minor < 0;
    }

    public function isZero(): bool
    {
        return $this->minor === 0;
    }

    public function toDecimal(int $scale = 2): string
    {
        $divisor = (string) (10 ** $scale);

        return bcdiv((string) $this->minor, $divisor, $scale);
    }

    public function format(): string
    {
        $major = $this->minor / 100;

        if ($this->currency === 'IDR') {
            return 'Rp '.number_format($major, 0, ',', '.');
        }

        return $this->currency.' '.number_format($major, 2, '.', ',');
    }

    public function __toString(): string
    {
        return $this->format();
    }

    public function jsonSerialize(): array
    {
        return [
            'minor' => $this->minor,
            'currency' => $this->currency,
            'decimal' => $this->toDecimal(2),
            'formatted' => $this->format(),
        ];
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException("Currency mismatch: {$this->currency} vs {$other->currency}");
        }
    }
}
