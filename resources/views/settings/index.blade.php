<x-app-layout>
    <div class="space-y-6 max-w-5xl mx-auto" data-gsap="page-enter">
        <x-erp.module-sub-nav module="organization" />

        <x-erp.page-header
            title="System Settings"
            description="Configure Point of Sales preferences, enterprise tax defaults, and transaction rounding rules.">
            <x-slot:breadcrumbs>
                <x-ui.breadcrumbs.item href="{{ route('dashboard') }}">Dashboard</x-ui.breadcrumbs.item>
                <x-ui.breadcrumbs.item active>Settings</x-ui.breadcrumbs.item>
            </x-slot:breadcrumbs>
        </x-erp.page-header>

        @if (session('success'))
            <x-ui.alert type="success" title="Success" class="rounded-2xl">
                {{ session('success') }}
            </x-ui.alert>
        @endif

        <div class="card-surface rounded-2xl border border-border p-6 shadow-xs">
            <form action="{{ route('settings.update') }}" method="POST" class="space-y-6">
                @csrf

                {{-- Master Cabang & Outlet Guidance (Single Source of Truth) --}}
                <div class="p-5 rounded-2xl border border-border bg-muted/40 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
                    <div class="flex items-start gap-3.5">
                        <div class="p-2.5 rounded-xl bg-background border border-border text-primary shrink-0 shadow-xs">
                            <span class="material-symbols-rounded text-2xl">store</span>
                        </div>
                        <div>
                            <h3 class="text-sm font-semibold text-foreground">Identitas & Profil Gerai Cabang (Multi-Store)</h3>
                            <p class="text-xs text-muted-foreground mt-0.5 leading-relaxed">
                                Nama toko, alamat fisik operasional, nomor telepon, dan identitas struk transaksi dikelola secara terpusat pada Master Gerai & Cabang demi menjamin integritas multi-outlet.
                            </p>
                        </div>
                    </div>
                    <a href="{{ route('stores.index') }}" wire:navigate class="inline-flex items-center gap-1.5 px-3.5 py-2 text-xs font-semibold rounded-xl bg-background border border-border hover:bg-muted text-foreground transition-colors shrink-0 shadow-xs">
                        <span>Kelola Master Cabang</span>
                        <span class="material-symbols-rounded text-sm">arrow_forward</span>
                    </a>
                </div>

                <div class="pt-6 border-t border-border">
                    <h2 class="text-base font-bold text-foreground mb-4">
                        Tax & Cashier Rounding Rules
                    </h2>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <x-ui.label for="tax_rate">Tax Rate (decimal, e.g., 0.11)</x-ui.label>
                            <x-ui.input type="number" step="0.01" min="0" max="1" id="tax_rate" name="tax_rate" value="{{ old('tax_rate', $settings['tax_rate'] ?? 0.11) }}" class="font-sans" />
                        </div>
                        <div>
                            <x-ui.label for="rounding_value">Rounding Multiple (e.g., 100)</x-ui.label>
                            <x-ui.input type="number" step="1" min="1" id="rounding_value" name="rounding_value" value="{{ old('rounding_value', $settings['rounding_value'] ?? 100) }}" class="font-sans" />
                        </div>
                        <div>
                            <x-ui.label for="rounding_behavior">Rounding Method</x-ui.label>
                            <x-ui.select id="rounding_behavior" name="rounding_behavior">
                                <option value="ROUND_NEAREST" @selected(($settings['rounding_behavior'] ?? 'ROUND_NEAREST') === 'ROUND_NEAREST')>Nearest (ROUND_NEAREST)</option>
                                <option value="ROUND_UP" @selected(($settings['rounding_behavior'] ?? '') === 'ROUND_UP')>Round Up (ROUND_UP)</option>
                                <option value="ROUND_DOWN" @selected(($settings['rounding_behavior'] ?? '') === 'ROUND_DOWN')>Round Down (ROUND_DOWN)</option>
                            </x-ui.select>
                        </div>
                    </div>
                </div>

                <div class="pt-4 border-t border-border flex justify-end">
                    <x-ui.button type="submit" variant="primary" class="gap-2">
                        <span class="material-symbols-rounded text-base">save</span>
                        Save Settings
                    </x-ui.button>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
