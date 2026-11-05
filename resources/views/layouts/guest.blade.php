<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">

    <title>@yield('title', config('app.name'))</title>
    <x-favicon />

    
    <!-- Self-Hosted Fonts Preload -->
    <link rel="preload" href="{{ asset('fonts/plus-jakarta-sans.woff2') }}" as="font" type="font/woff2" crossorigin>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body class="font-sans antialiased text-foreground bg-card min-h-screen overflow-x-hidden selection:bg-selection selection:text-selection-foreground">

    {{-- Aksesibilitas: Lewati ke konten utama (WCAG 2.4.1) --}}
    <a href="#main-content"
        class="sr-only focus:not-sr-only focus:fixed focus:top-3 focus:left-3 focus:z-50 focus:px-4 focus:py-2 focus:bg-primary focus:text-primary-foreground focus:text-xs focus:font-semibold focus:rounded-lg focus:shadow-lg focus:ring-2 focus:ring-ring">
        Lewati ke konten utama
    </a>

    <div id="swup" class="transition-fade min-h-screen grid grid-cols-1 md:grid-cols-2 lg:grid-cols-12 w-full bg-card overflow-x-hidden">
        <!-- KIRI: KONTEN UTAMA (7 cols di lg, 1 col di md, single col di mobile) -->
        <div class="md:col-span-1 lg:col-span-7 flex flex-col justify-between p-6 sm:p-10 lg:p-12 xl:p-16 w-full relative bg-card min-h-screen">
            <!-- Form Content Mount (Strictly clamped to max-w-sm to prevent horizontal stretching) -->
            <div id="main-content" class="my-auto py-4 sm:py-6 w-full max-w-sm mx-auto" style="max-width: 384px;">
                @hasSection('content')
                    @yield('content')
                @else
                    {{ $slot ?? '' }}
                @endif
            </div>

            <!-- Footer Bar with Active Legal Modals -->
            <div x-data="{ modal: null }" class="w-full max-w-sm mx-auto md:max-w-none flex items-center justify-between text-xs text-muted-foreground pt-8 mt-auto border-t border-border" style="max-width: 384px;">
                <span>© {{ date('Y') }} Apeiron Systems</span>
                <div class="flex items-center gap-4">
                    <button type="button" @click="modal = 'privacy'" class="hover:text-foreground transition-colors cursor-pointer font-medium">Privacy</button>
                    <button type="button" @click="modal = 'terms'" class="hover:text-foreground transition-colors cursor-pointer font-medium">Terms</button>
                </div>

                {{-- Legal Modal Dialog --}}
                <div x-show="modal !== null" x-cloak
                     @keydown.escape.window="modal = null"
                     class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-overlay-scrim backdrop-blur-xs text-popover-foreground"
                     role="dialog" aria-modal="true">
                    <div @click.outside="modal = null"
                         class="bg-popover rounded-2xl max-w-lg w-full max-h-[85vh] flex flex-col shadow-2xl border border-border overflow-hidden text-left">
                        {{-- Modal Header --}}
                        <div class="p-5 border-b border-border flex items-center justify-between">
                            <h3 class="text-base font-bold text-foreground" x-text="modal === 'privacy' ? 'Privacy Policy' : 'Terms of Service'"></h3>
                            <button type="button" @click="modal = null" class="w-8 h-8 rounded-lg flex items-center justify-center text-muted-foreground hover:text-foreground hover:bg-muted transition-colors" aria-label="Close modal">
                                <span class="material-symbols-rounded text-lg">close</span>
                            </button>
                        </div>

                        {{-- Modal Content --}}
                        <div class="p-5 overflow-y-auto text-xs text-muted-foreground space-y-4 leading-relaxed">
                            <template x-if="modal === 'privacy'">
                                <div class="space-y-3">
                                    <p class="font-semibold text-foreground">Kebijakan Privasi Apeiron POS (Multi-Tenant SaaS)</p>
                                    <p>Apeiron Systems berkomitmen untuk menjaga keamanan dan privasi data transaksi bisnis Anda sesuai dengan regulasi perlindungan data yang berlaku (UU PDP No. 27/2022).</p>
                                    <h4 class="font-bold text-foreground pt-1">1. Isolasi Data Multi-Tenant</h4>
                                    <p>Setiap data transaksi penjualan, katalog produk, inventori stok bahan baku, dan riwayat pelanggan dienkripsi dan diisolasi secara ketat berdasarkan Tenant ID organisasi Anda.</p>
                                    <h4 class="font-bold text-foreground pt-1">2. Penyimpanan &amp; Audit Log</h4>
                                    <p>Aktivitas sistem kasir dan perubahan konfigurasi dicatat dalam Audit Trail untuk keperluan rekonsiliasi dan investigasi operasional.</p>
                                    <h4 class="font-bold text-foreground pt-1">3. Kontak Perlindungan Data</h4>
                                    <p>Untuk pertanyaan mengenai kebijakan privasi atau permintaan penghapusan akun, hubungi tim dukungan kami di <span class="font-mono text-foreground">support@apeironsystems.com</span>.</p>
                                </div>
                            </template>

                            <template x-if="modal === 'terms'">
                                <div class="space-y-3">
                                    <p class="font-semibold text-foreground">Syarat dan Ketentuan Layanan (Terms of Service)</p>
                                    <p>Selamat datang di Apeiron POS. Dengan mendaftar dan menggunakan layanan kasir, dapur (KDS), dan manajemen ERP kami, Anda menyetujui ketentuan berikut:</p>
                                    <h4 class="font-bold text-foreground pt-1">1. Tanggung Jawab Akun</h4>
                                    <p>Pengguna bertanggung jawab atas kerahasiaan kredensial login (PIN shift, email, kata sandi, dan token autentikasi 2FA) yang digunakan pada perangkat workstation kasir.</p>
                                    <h4 class="font-bold text-foreground pt-1">2. Ketersediaan Layanan (SLA)</h4>
                                    <p>Kami menyediakan fungsionalitas transaksi offline-capable untuk memastikan operasional kasir tetap berjalan tanpa gangguan saat koneksi internet terputus.</p>
                                    <h4 class="font-bold text-foreground pt-1">3. Hak Kekayaan Intelektual</h4>
                                    <p>Seluruh antarmuka, komponen, modul sistem, dan arsitektur perangkat lunak adalah hak milik Apeiron Systems.</p>
                                </div>
                            </template>
                        </div>

                        {{-- Modal Footer --}}
                        <div class="p-4 bg-muted border-t border-border flex justify-end">
                            <button type="button" @click="modal = null" class="px-4 py-2 bg-primary hover:bg-foreground text-primary-foreground text-xs font-semibold rounded-xl transition-colors cursor-pointer">
                                Saya Mengerti
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- KANAN: EDITORIAL BRAND PANEL (5 cols di lg, 1 col di md, disembunyikan di mobile < 768px) -->
        @hasSection('panel')
            @yield('panel')
        @else
            <div class="hidden md:flex md:col-span-1 lg:col-span-5 editorial-surface text-primary-foreground p-8 sm:p-10 lg:p-12 xl:p-14 flex-col justify-between select-none relative overflow-hidden bg-foreground min-h-screen" id="anim-panel-right" aria-hidden="true">
                <div class="absolute inset-0 bg-cover bg-center opacity-20 mix-blend-luminosity pointer-events-none"
                    style="background-image: url('{{ asset('assets/images/auth/panelKananLogin.avif') }}');"></div>

                <div class="relative z-10 text-xs text-shell-foreground font-medium tracking-wide">
                    Apeiron Systems
                </div>

                <div class="relative z-10 my-auto max-w-sm space-y-4">
                    <p class="text-[19px] lg:text-[21px] font-normal tracking-tight text-background leading-[1.45]">
                        Point of sale, inventory ledger, and shift reconciliation built for multi-branch hospitality.
                    </p>
                    <p class="text-xs text-shell-foreground leading-relaxed">
                        Every order, stock movement, and cash drawer event is recorded with strict branch isolation.
                    </p>
                </div>

                <div class="relative z-10 flex items-center justify-between text-xs text-shell-foreground pt-6 border-t border-shell-border">
                    <span>Enterprise Point of Sale</span>
                    <span>© {{ date('Y') }}</span>
                </div>
            </div>
        @endif
    </div>

    <x-ui.toast />
    <x-erp.confirm-dialog />

    @yield('scripts')

</body>

</html>
