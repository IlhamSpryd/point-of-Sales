@props([
    'name' => 'API Access Token',
    'token' => 'apeiron_sec_••••••••••••••••',
    'scopes' => ['sales:read', 'orders:write'],
    'createdAt' => 'Today',
])

<div {{ $attributes->twMerge('rounded-xl border border-border bg-card p-4 shadow-xs select-none space-y-3 transition-all hover:border-border') }}
    x-data="{
        copied: false,
        tokenVal: @js($token),
        copyToken() {
            navigator.clipboard.writeText(this.tokenVal).then(() => {
                this.copied = true;
                setTimeout(() => this.copied = false, 2000);
            });
        }
    }">
    <div class="flex items-start justify-between gap-3">
        <div>
            <h4 class="text-sm font-bold text-foreground tracking-tight">
                {{ $name }}
            </h4>
            <p class="text-[11px] text-muted-foreground mt-0.5">
                Created on {{ $createdAt }}
            </p>
        </div>

        <button type="button"
            class="text-xs font-semibold text-destructive hover:underline cursor-pointer">
            Cabut Token
        </button>
    </div>

    {{-- Masked Token with One-Click Copy --}}
    <div class="flex items-center gap-2 bg-muted p-2 rounded-xl border border-border">
        <span class="material-symbols-rounded text-sm text-muted-foreground pl-1">key</span>
        <span class="font-sans text-xs text-foreground truncate flex-1" x-text="tokenVal"></span>
        <button type="button"
            @click="copyToken()"
            class="px-2.5 py-1 rounded-lg text-xs font-semibold bg-card border border-border text-foreground hover:bg-muted shadow-xs transition-colors cursor-pointer shrink-0 flex items-center gap-1">
            <span class="material-symbols-rounded text-sm" x-text="copied ? 'check' : 'content_copy'"></span>
            <span x-text="copied ? 'Tersalin' : 'Salin'">Salin</span>
        </button>
    </div>

    {{-- Scopes Chips --}}
    @if (!empty($scopes))
        <div class="flex flex-wrap items-center gap-1.5 pt-1">
            <span class="text-[10px] uppercase font-bold text-muted-foreground mr-1">Izin Scopes:</span>
            @foreach ($scopes as $s)
                <span class="px-2 py-0.5 rounded text-[10px] font-sans font-medium bg-secondary text-secondary-foreground border border-border">
                    {{ $s }}
                </span>
            @endforeach
        </div>
    @endif
</div>
