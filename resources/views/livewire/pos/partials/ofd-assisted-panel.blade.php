<div class="mb-2 p-3 bg-muted/50 border border-border rounded-[8px] space-y-2 shrink-0 text-xs">
    <div class="flex items-center justify-between">
        <span class="font-semibold text-foreground uppercase tracking-wider flex items-center gap-1.5">
            <span class="material-symbols-rounded text-[16px] text-foreground">moped</span>
            <span>OFD Assisted: {{ strtoupper($orderType) }}</span>
        </span>
        <span class="text-[11px] font-sans text-muted-foreground tabular-nums">Komisi {{ $this->ofdCommissionRate }}%</span>
    </div>

    <div class="grid grid-cols-2 gap-2">
        <div>
            <label for="pos-ofd-short-code" class="block text-[11px] font-medium text-muted-foreground mb-0.5">
                Kode Order / PIN *
            </label>
            <input type="text" id="pos-ofd-short-code" wire:model="externalShortCode"
                placeholder="{{ $orderType === 'grabfood' ? 'GF-102' : 'G-49' }}"
                class="w-full h-8 px-2.5 text-xs bg-background border border-input rounded-[6px] text-foreground placeholder-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring font-sans uppercase font-semibold" />
            @error('externalShortCode')
                <span class="text-[10.5px] text-destructive block mt-0.5">{{ $message }}</span>
            @enderror
        </div>
        <div>
            <label for="pos-ofd-promo-subsidy" class="block text-[11px] font-medium text-muted-foreground mb-0.5">
                Subsidi Promo (Rp)
            </label>
            <input type="number" id="pos-ofd-promo-subsidy" wire:model.live.debounce.300ms="merchantPromoSubsidy" min="0" step="1000"
                placeholder="0"
                class="w-full h-8 px-2.5 text-xs bg-background border border-input rounded-[6px] text-foreground placeholder-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring font-sans tabular-nums" />
        </div>
    </div>

    <div class="grid grid-cols-2 gap-2">
        <div>
            <label for="pos-ofd-driver-name" class="block text-[11px] font-medium text-muted-foreground mb-0.5">
                Nama Driver (Opsional)
            </label>
            <input type="text" id="pos-ofd-driver-name" wire:model="driverName"
                placeholder="Pak Budi"
                class="w-full h-8 px-2.5 text-xs bg-background border border-input rounded-[6px] text-foreground placeholder-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring" />
        </div>
        <div>
            <label for="pos-ofd-driver-phone" class="block text-[11px] font-medium text-muted-foreground mb-0.5">
                No. HP Driver (Opsional)
            </label>
            <input type="text" id="pos-ofd-driver-phone" wire:model="driverPhone"
                placeholder="0812xxxx"
                class="w-full h-8 px-2.5 text-xs bg-background border border-input rounded-[6px] text-foreground placeholder-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring font-sans tabular-nums" />
        </div>
    </div>

    <!-- Live Summary Estimasi OFD -->
    <div class="pt-2 border-t border-border/70 space-y-1 text-[11.5px] tabular-nums">
        <div class="flex justify-between text-muted-foreground">
            <span>Komisi Platform ({{ $this->ofdCommissionRate }}%)</span>
            <span class="text-foreground font-medium">-Rp {{ number_format($this->estimatedOfdCommission, 0, ',', '.') }}</span>
        </div>
        @if ($merchantPromoSubsidy > 0)
            <div class="flex justify-between text-muted-foreground">
                <span>Subsidi Promo Merchant</span>
                <span class="text-foreground font-medium">-Rp {{ number_format($merchantPromoSubsidy, 0, ',', '.') }}</span>
            </div>
        @endif
        <div class="flex justify-between items-baseline font-semibold text-foreground pt-0.5 border-t border-border/50">
            <span>Estimasi Net Payout (1125)</span>
            <span class="text-xs text-foreground font-bold">Rp {{ number_format($this->estimatedOfdNetPayout, 0, ',', '.') }}</span>
        </div>
    </div>
</div>

