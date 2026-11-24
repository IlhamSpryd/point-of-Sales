<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Format\Date;
use App\Support\Format\Rupiah;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

class FormatHelpersTest extends TestCase
{
    public function test_rupiah_format_uses_utf8_non_breaking_space_and_indonesian_separators(): void
    {
        $nbsp = "\u{00A0}";

        $this->assertSame("Rp{$nbsp}10.000", Rupiah::format(10000));
        $this->assertSame("Rp{$nbsp}1.500.000", Rupiah::format(1500000));
        $this->assertSame("Rp{$nbsp}10.000,50", Rupiah::format(10000.5, true));
        $this->assertSame("-Rp{$nbsp}25.000", Rupiah::format(-25000));
        $this->assertSame("Rp{$nbsp}1,5 jt", Rupiah::compact(1500000));
        $this->assertSame("Rp{$nbsp}2,0 M", Rupiah::compact(2000000000));
    }

    public function test_date_helper_formats_consistently_with_wib_and_null_safety(): void
    {
        $dt = Carbon::parse('2026-11-25 14:30:00');

        $this->assertSame('-', Date::display(null));
        $this->assertSame('-', Date::displayWithTime(null));
        $this->assertSame('-', Date::short(null));
        $this->assertSame('25 Nov 2026 14:30 WIB', Date::displayWithTime($dt));
        $this->assertSame('25/11/2026', Date::short($dt));
        $this->assertStringContainsString('2026', Date::display($dt));
    }
}
