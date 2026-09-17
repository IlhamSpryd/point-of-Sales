<?php

namespace App\View\Components;

use Illuminate\View\Component;
use Illuminate\View\View;

/**
 * Komponen View: Menghubungkan layout utama aplikasi (App)
 * kepada layar halaman administrator setelah sukses login.
 */
class AppLayout extends Component
{
    public $noPadding;

    public function __construct($noPadding = false)
    {
        $this->noPadding = $noPadding;
    }

    /**
     * Memanggil sumber cetak biru (Blueprint) balok tampilan HTML Blade.
     */
    public function render(): View
    {
        return view('layouts.app');
    }
}
