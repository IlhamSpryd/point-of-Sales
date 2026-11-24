<div class="space-y-6" data-gsap="page-enter">
    <x-erp.module-sub-nav module="pos-ops" />

    <!-- Header -->
    <x-erp.page-header
        title="Channel Integration (OFD Channel Config)"
        description="Kelola pengaturan kanal Online Food Delivery (GrabFood & GoFood) per cabang, komisi platform, harga khusus, dan verifikasi webhook HMAC.">
        <x-slot:breadcrumbs>
            <x-ui.breadcrumbs.item href="{{ route('stores.index') }}">Dashboard</x-ui.breadcrumbs.item>
            <x-ui.breadcrumbs.item href="{{ route('products.index') }}">Catalog</x-ui.breadcrumbs.item>
            <x-ui.breadcrumbs.item href="{{ route('integrations.channel-mapping') }}">Channel Integrations</x-ui.breadcrumbs.item>
            <x-ui.breadcrumbs.item active>OFD Config</x-ui.breadcrumbs.item>
        </x-slot:breadcrumbs>
    </x-erp.page-header>

    <!-- Integration Tabs Switcher -->
    <div class="flex items-center gap-2 border-b border-border pb-2">
        <a href="{{ route('integrations.channel-mapping') }}"
            class="px-3.5 py-1.5 rounded-md text-xs font-medium text-muted-foreground hover:text-foreground hover:bg-muted transition-colors">
            SKU Mapping
        </a>
        <a href="{{ route('integrations.ofd-channels') }}"
            class="px-3.5 py-1.5 rounded-md text-xs font-semibold bg-foreground text-background shadow-xs transition-colors">
            OFD Channel Config (GrabFood / GoFood)
        </a>
    </div>

    @if (session()->has('message'))
        <x-ui.alert type="success" title="Berhasil" class="rounded-xl">
            {{ session('message') }}
        </x-ui.alert>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- OFD Configuration Form -->
        <div class="card-surface p-6 rounded-2xl border border-border lg:col-span-2 space-y-5 shadow-xs">
            <!-- Provider Selector Tabs -->
            <div class="flex items-center justify-between border-b border-border pb-4">
                <div class="inline-flex p-1 bg-muted rounded-lg border border-border h-10 items-center">
                    <button type="button" wire:click="setProvider('grabfood')"
                        class="px-4 py-1.5 rounded-md text-xs font-semibold transition-all cursor-pointer {{ $provider === 'grabfood' ? 'bg-foreground text-background shadow-xs' : 'text-muted-foreground hover:text-foreground' }}">
                        GrabFood
                    </button>
                    <button type="button" wire:click="setProvider('gofood')"
                        class="px-4 py-1.5 rounded-md text-xs font-semibold transition-all cursor-pointer {{ $provider === 'gofood' ? 'bg-foreground text-background shadow-xs' : 'text-muted-foreground hover:text-foreground' }}">
                        GoFood
                    </button>
                </div>
                <div class="flex items-center gap-2">
                    <span class="text-xs font-medium text-muted-foreground">Status Kanal:</span>
                    <label class="relative inline-flex items-center cursor-pointer">
                        <input type="checkbox" wire:model="isActive" class="sr-only peer">
                        <div class="w-9 h-5 bg-muted peer-focus:outline-none peer-focus:ring-2 peer-focus:ring-ring rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-background after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-foreground after:border-border after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-foreground"></div>
                    </label>
                    <span class="text-xs font-semibold {{ $isActive ? 'text-positive' : 'text-muted-foreground' }}">
                        {{ $isActive ? 'Aktif' : 'Nonaktif' }}
                    </span>
                </div>
            </div>

            <form wire:submit="saveConfig" class="space-y-4">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <x-ui.label for="ofd-merchant-id">Merchant ID (Store ID di Platform) *</x-ui.label>
                        <x-ui.input id="ofd-merchant-id" type="text" wire:model="merchantId" placeholder="mis. GRAB_RESTO_001 / GF_ID_99" class="font-sans text-xs" />
                        @error('merchantId') <span class="text-xs text-destructive mt-1 block">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <x-ui.label for="ofd-client-id">Client ID / App Key (Opsional)</x-ui.label>
                        <x-ui.input id="ofd-client-id" type="text" wire:model="clientId" placeholder="mis. app_client_grab_prod" class="font-sans text-xs" />
                        @error('clientId') <span class="text-xs text-destructive mt-1 block">{{ $message }}</span> @enderror
                    </div>
                </div>

                <!-- Webhook Signing Secret -->
                <div class="p-3.5 bg-muted/40 border border-border rounded-xl space-y-2">
                    <div class="flex items-center justify-between">
                        <x-ui.label for="ofd-signing-secret" class="!mb-0">HMAC Webhook Signing Secret *</x-ui.label>
                        <button type="button" wire:click="generateSigningSecret"
                            class="text-xs font-medium text-primary hover:underline flex items-center gap-1 cursor-pointer">
                            <span class="material-symbols-rounded text-[14px]">autorenew</span>
                            <span>Generate Secret Otomatis</span>
                        </button>
                    </div>

                    <div class="relative">
                        <x-ui.input id="ofd-signing-secret" type="text" wire:model="signingSecret"
                            placeholder="{{ $hasExistingSecret ? 'Tersimpan (••••••••••••••••••••••••••••••••)' : 'Masukkan secret minimal 16 karakter...' }}"
                            class="font-mono text-xs pr-20" />
                        @if ($signingSecret)
                            <span class="absolute right-2.5 top-1/2 -translate-y-1/2 text-[10px] text-positive font-semibold uppercase tracking-wider">
                                Siap Simpan
                            </span>
                        @elseif ($hasExistingSecret)
                            <span class="absolute right-2.5 top-1/2 -translate-y-1/2 text-[10px] text-muted-foreground font-semibold uppercase tracking-wider">
                                Tersimpan
                            </span>
                        @endif
                    </div>
                    @error('signingSecret') <span class="text-xs text-destructive mt-0.5 block">{{ $message }}</span> @enderror
                    <p class="text-[11px] text-muted-foreground leading-relaxed">
                        Secret ini digunakan untuk memverifikasi keaslian payload webhook server-to-server dari {{ strtoupper($provider) }}.
                    </p>
                </div>

                <!-- Commission Rate & Markup -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <x-ui.label for="ofd-commission-rate">Komisi Platform (%) *</x-ui.label>
                        <x-ui.input id="ofd-commission-rate" type="number" step="0.1" min="0" max="100" wire:model="commissionRatePct" placeholder="20.00" class="font-sans tabular-nums text-xs" />
                        <span class="text-[11px] text-muted-foreground mt-0.5 block">Potongan resmi platform (default 20%). Dicatat ke akun 5200.</span>
                        @error('commissionRatePct') <span class="text-xs text-destructive mt-1 block">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <x-ui.label for="ofd-price-markup">Markup Harga (%)</x-ui.label>
                        <x-ui.input id="ofd-price-markup" type="number" step="0.1" min="0" max="100" wire:model="priceMarkupPct" placeholder="0.00" class="font-sans tabular-nums text-xs" />
                        <span class="text-[11px] text-muted-foreground mt-0.5 block">Penyesuaian markup harga jual menu di platform OFD.</span>
                        @error('priceMarkupPct') <span class="text-xs text-destructive mt-1 block">{{ $message }}</span> @enderror
                    </div>
                </div>

                <!-- Price List & Auto Accept -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <x-ui.label for="ofd-price-list">Price List Khusus (Opsional)</x-ui.label>
                        <x-ui.select id="ofd-price-list" wire:model="priceListId">
                            <option value="">-- Gunakan Harga Default Outlet --</option>
                            @foreach ($priceLists as $pl)
                                <option value="{{ $pl->id }}">{{ $pl->name }}</option>
                            @endforeach
                        </x-ui.select>
                        @error('priceListId') <span class="text-xs text-destructive mt-1 block">{{ $message }}</span> @enderror
                    </div>

                    <div class="flex items-center gap-3 pt-6">
                        <label class="relative inline-flex items-center cursor-pointer">
                            <input type="checkbox" wire:model="autoAcceptOrder" class="sr-only peer">
                            <div class="w-9 h-5 bg-muted peer-focus:outline-none peer-focus:ring-2 peer-focus:ring-ring rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-background after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-foreground after:border-border after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-foreground"></div>
                        </label>
                        <div>
                            <span class="text-xs font-semibold text-foreground block">Auto-Accept Webhook</span>
                            <span class="text-[11px] text-muted-foreground block">Otomatis terima order dan teruskan ke KDS</span>
                        </div>
                    </div>
                </div>

                <div class="pt-2">
                    <x-ui.button type="submit" variant="primary" class="w-full justify-center min-h-[40px]" wire:loading.attr="disabled" wire:target="saveConfig">
                        <span wire:loading wire:target="saveConfig" class="h-4 w-4 animate-spin rounded-full border-2 border-current/30 border-t-current mr-1.5"></span>
                        Simpan Konfigurasi {{ strtoupper($provider) }}
                    </x-ui.button>
                </div>
            </form>
        </div>

        <!-- Webhook Integration Technical Guide -->
        <div class="space-y-4 lg:col-span-1">
            <div class="card-surface p-5 rounded-2xl border border-border shadow-xs space-y-4">
                <div class="flex items-center gap-2 border-b border-border pb-3">
                    <span class="material-symbols-rounded text-[20px] text-foreground">webhook</span>
                    <h3 class="text-sm font-bold text-foreground">Webhook Integration</h3>
                </div>

                <div class="space-y-3 text-xs">
                    <div>
                        <span class="text-[11px] font-semibold text-muted-foreground uppercase tracking-wider block mb-1">Webhook Endpoint URL</span>
                        <div class="p-2.5 bg-muted rounded-lg border border-border font-mono text-[11px] text-foreground break-all select-all">
                            {{ $webhookUrl }}
                        </div>
                    </div>

                    <div>
                        <span class="text-[11px] font-semibold text-muted-foreground uppercase tracking-wider block mb-1">HMAC Signature Header</span>
                        <div class="p-2.5 bg-muted rounded-lg border border-border font-mono text-[11px] text-foreground select-all">
                            {{ $headerName }}
                        </div>
                    </div>

                    <div class="p-3 bg-muted/50 rounded-xl border border-border text-[11.5px] text-muted-foreground leading-relaxed space-y-2">
                        <p class="font-semibold text-foreground">Aturan Keamanan Tier 2:</p>
                        <ul class="list-disc list-inside space-y-1">
                            <li>Payload diverifikasi via <code class="font-mono text-foreground">hash_hmac('sha256', payload, secret)</code>.</li>
                            <li>Order valid langsung menghasilkan tiket KDS dan pesanan POS.</li>
                            <li>Jurnal otomatis mencatat piutang kliring (1125), komisi (5200), dan pendapatan seimbang Rp 0,00.</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
