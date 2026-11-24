<?php

declare(strict_types=1);

namespace App\Support\Format;

use Carbon\Carbon;

class Date
{
    /**
     * Display a date in Indonesian locale format: "12 Jan 2025".
     */
    public static function display(Carbon|string|null $date): string
    {
        if ($date === null) {
            return '-';
        }

        $carbon = $date instanceof Carbon ? $date : Carbon::parse($date);

        return $carbon->locale('id')->isoFormat('D MMM YYYY');
    }

    /**
     * Display a datetime with WIB timezone label: "12 Jan 2025 14:30 WIB".
     */
    public static function displayWithTime(Carbon|string|null $date): string
    {
        if ($date === null) {
            return '-';
        }

        $carbon = $date instanceof Carbon ? $date : Carbon::parse($date);

        return $carbon->format('d M Y H:i').' WIB';
    }

    /**
     * Display a short date: "12/01/2025".
     */
    public static function short(Carbon|string|null $date): string
    {
        if ($date === null) {
            return '-';
        }

        $carbon = $date instanceof Carbon ? $date : Carbon::parse($date);

        return $carbon->format('d/m/Y');
    }
}
