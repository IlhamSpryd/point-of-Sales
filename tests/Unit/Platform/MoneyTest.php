<?php

declare(strict_types=1);

namespace Tests\Unit\Platform;

use App\Platform\Money\Money;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_factory_methods_create_expected_instances(): void
    {
        $idr = Money::idr(50000);
        $this->assertSame(5000000, $idr->minor);
        $this->assertSame('IDR', $idr->currency);
        $this->assertSame('50000.00', $idr->toDecimal(2));

        $fromDec = Money::fromDecimal('123.45', 'USD');
        $this->assertSame(12345, $fromDec->minor);
        $this->assertSame('USD', $fromDec->currency);
        $this->assertSame('123.45', $fromDec->toDecimal(2));

        $zero = Money::zero('IDR');
        $this->assertSame(0, $zero->minor);
        $this->assertTrue($zero->isZero());
        $this->assertFalse($zero->isNegative());
    }

    public function test_addition_and_subtraction(): void
    {
        $m1 = Money::idr(15000);
        $m2 = Money::idr(25000);

        $sum = $m1->add($m2);
        $this->assertSame(4000000, $sum->minor);
        $this->assertSame('40000.00', $sum->toDecimal());

        $diff = $m2->subtract($m1);
        $this->assertSame(1000000, $diff->minor);
        $this->assertSame('10000.00', $diff->toDecimal());
    }

    public function test_multiplication_with_deterministic_rounding(): void
    {
        $money = Money::fromDecimal('100.00', 'IDR'); // 10000 minor

        $multiplied = $money->multiply('0.11'); // 11% tax = 1100 minor
        $this->assertSame(1100, $multiplied->minor);
        $this->assertSame('11.00', $multiplied->toDecimal());

        // Test round half up: 100 * 0.0055 = 0.55 -> 55 minor
        $halfUp = Money::fromDecimal('10.00', 'USD')->multiply('0.0055');
        // 1000 minor * 0.0055 = 5.5 minor -> 6 minor
        $this->assertSame(6, $halfUp->minor);
    }

    public function test_allocate_distributes_exact_total_with_zero_remainder_loss(): void
    {
        // 100 minor units split into 3 parts: 34, 33, 33
        $money = new Money(100, 'IDR');
        $parts = $money->allocate(3);

        $this->assertCount(3, $parts);
        $this->assertSame(34, $parts[0]->minor);
        $this->assertSame(33, $parts[1]->minor);
        $this->assertSame(33, $parts[2]->minor);

        $sum = array_sum(array_map(fn (Money $m) => $m->minor, $parts));
        $this->assertSame(100, $sum);

        // Negative allocation split
        $neg = new Money(-10, 'IDR');
        $negParts = $neg->allocate(3);
        $this->assertCount(3, $negParts);
        $this->assertSame(-4, $negParts[0]->minor);
        $this->assertSame(-3, $negParts[1]->minor);
        $this->assertSame(-3, $negParts[2]->minor);
        $this->assertSame(-10, array_sum(array_map(fn (Money $m) => $m->minor, $negParts)));
    }

    public function test_allocate_throws_exception_on_zero_or_negative_parts(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $money = Money::idr(1000);
        $money->allocate(0);
    }

    public function test_currency_mismatch_throws_exception(): void
    {
        $idr = Money::idr(10000);
        $usd = Money::fromDecimal('10.00', 'USD');

        $this->expectException(InvalidArgumentException::class);
        $idr->add($usd);
    }

    public function test_comparisons(): void
    {
        $m1 = Money::idr(1000);
        $m2 = Money::idr(2000);
        $m3 = Money::idr(1000);
        $neg = Money::fromDecimal('-5.00', 'IDR');

        $this->assertTrue($m1->equals($m3));
        $this->assertFalse($m1->equals($m2));
        $this->assertTrue($m2->greaterThan($m1));
        $this->assertTrue($m1->lessThan($m2));
        $this->assertTrue($neg->isNegative());
        $this->assertFalse($m1->isNegative());
    }

    public function test_formatting_and_serialization(): void
    {
        $idr = Money::idr(50000);
        $this->assertSame('Rp 50.000', $idr->format());
        $this->assertSame('Rp 50.000', (string) $idr);

        $usd = Money::fromDecimal('1234.50', 'USD');
        $this->assertSame('USD 1,234.50', $usd->format());

        $json = $idr->jsonSerialize();
        $this->assertSame(5000000, $json['minor']);
        $this->assertSame('IDR', $json['currency']);
        $this->assertSame('50000.00', $json['decimal']);
    }
}
