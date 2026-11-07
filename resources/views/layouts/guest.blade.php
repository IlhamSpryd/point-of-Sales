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
    @livewireStyles

    <style>
        [x-cloak] {
            display: none !important;
        }
    </style>
</head>

<body class="font-sans antialiased text-foreground bg-card min-h-screen overflow-x-hidden selection:bg-selection selection:text-selection-foreground">

    {{-- Accessibility: Skip to main content (WCAG 2.4.1) --}}
    <a href="#main-content"
        class="sr-only focus:not-sr-only focus:fixed focus:top-3 focus:left-3 focus:z-50 focus:px-4 focus:py-2 focus:bg-primary focus:text-primary-foreground focus:text-xs focus:font-semibold focus:rounded-lg focus:shadow-lg focus:ring-2 focus:ring-ring">
        Skip to main content
    </a>

    <div id="swup" class="transition-fade min-h-screen grid grid-cols-1 md:grid-cols-2 lg:grid-cols-12 w-full bg-card overflow-x-hidden">
        <!-- KIRI: KONTEN UTAMA (7 cols di lg, 1 col di md, single col di mobile) -->
        <div class="md:col-span-1 lg:col-span-7 flex flex-col justify-between p-6 sm:p-10 lg:p-12 xl:p-16 w-full relative bg-card min-h-screen">
            <!-- Header Brand Logo -->
            <div class="w-full max-w-sm mx-auto mb-6" style="max-width: 384px;">
                <a href="{{ url('/') }}" aria-label="Apeiron Systems" class="inline-block focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring rounded-lg">
                    <x-brand-logo variant="wordmark" class="h-7 w-auto text-foreground" />
                </a>
            </div>

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
                                    <p class="font-semibold text-foreground">Apeiron POS Privacy Policy (Multi-Tenant SaaS)</p>
                                    <p>Apeiron Systems is committed to safeguarding the security and confidentiality of your enterprise business and transaction data in strict compliance with applicable global data protection standards.</p>
                                    <h4 class="font-bold text-foreground pt-1">1. Multi-Tenant Data Isolation</h4>
                                    <p>All sales transactions, product catalogs, raw material inventory records, and customer histories are encrypted in transit and at rest, strictly isolated by your organization's Tenant ID.</p>
                                    <h4 class="font-bold text-foreground pt-1">2. Retention &amp; Audit Trails</h4>
                                    <p>POS terminal operations, financial movements, and configuration changes are recorded in an immutable Audit Trail for reconciliation and compliance verification.</p>
                                    <h4 class="font-bold text-foreground pt-1">3. Data Protection Contact</h4>
                                    <p>For inquiries regarding our privacy standards or data management requests, please contact our security team at <span class="font-sans text-foreground">support@apeironsystems.com</span>.</p>
                                </div>
                            </template>

                            <template x-if="modal === 'terms'">
                                <div class="space-y-3">
                                    <p class="font-semibold text-foreground">Terms of Service</p>
                                    <p>Welcome to Apeiron POS. By registering and utilizing our POS, Kitchen Display System (KDS), and ERP services, you agree to the following terms:</p>
                                    <h4 class="font-bold text-foreground pt-1">1. Account &amp; Credential Security</h4>
                                    <p>Users are strictly responsible for maintaining the confidentiality of their authentication credentials (shift PINs, email addresses, passwords, and 2FA tokens) across all workstation devices.</p>
                                    <h4 class="font-bold text-foreground pt-1">2. Service Availability &amp; Offline Operations</h4>
                                    <p>We provide offline-capable transaction architecture to ensure uninterrupted register operations during network disruptions.</p>
                                    <h4 class="font-bold text-foreground pt-1">3. Intellectual Property</h4>
                                    <p>All interfaces, proprietary components, workflows, system modules, and software architecture are the exclusive property of Apeiron Systems.</p>
                                </div>
                            </template>
                        </div>

                        {{-- Modal Footer --}}
                        <div class="p-4 bg-muted border-t border-border flex justify-end">
                            <button type="button" @click="modal = null" class="px-4 py-2 bg-primary hover:bg-foreground text-primary-foreground text-xs font-semibold rounded-xl transition-colors cursor-pointer">
                                Close
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

                <div class="relative z-10 flex items-center">
                    <x-brand-logo variant="wordmark" class="h-6 w-auto text-background" />
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

    @livewireScripts
    @yield('scripts')

</body>

</html>
