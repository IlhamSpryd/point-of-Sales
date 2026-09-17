<?php

namespace App\View\Components;

use Illuminate\View\Component;
use Illuminate\View\View;

/**
 * Komponen View: Menghubungkan layout khusus partisipan/guest
 * (Misal: Form Pendaftaran atau Form Login pengunjung luar).
 */
class GuestLayout extends Component
{
    /**
     * Memanggil sumber cetak biru (Blueprint) balok tampilan target Blade.
     */
    public function render(): View
    {
        return view('layouts.guest');
    }
}
