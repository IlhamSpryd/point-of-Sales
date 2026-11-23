{{-- ========================================================================= --}}
{{-- SECTION G: OPERATIONAL ALERTS & BUSINESS INSIGHTS — COMPACT 14" SCALE     --}}
{{-- ========================================================================= --}}
@php
    $alerts = $executiveData['operational_alerts'] ?? [];
@endphp

<section aria-label="Operational Alerts and Business Insights" class="space-y-3">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
        <div>
            <h2 class="text-[13.5px] font-semibold text-foreground tracking-[-0.01em]">Operational Alerts &amp; Business Insights</h2>
            <p class="text-[11px] text-muted-foreground mt-0.5">
                Active exceptions detected across sales velocity, inventory buffers, and purchasing workflows.
            </p>
        </div>
        <div class="text-[11px] font-semibold text-muted-foreground tabular-nums">
            {{ count($alerts) }} Monitored Insights
        </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
        @forelse($alerts as $alert)
            @php
                $sev = $alert['severity'] ?? ($alert['type'] ?? 'neutral');
                $borderAccent = match($sev) {
                    'critical' => 'border-l-destructive',
                    'warning' => 'border-l-warning',
                    'positive' => 'border-l-positive',
                    default => 'border-l-muted-foreground'
                };
            @endphp
            <div wire:key="alert-card-{{ $alert['id'] ?? $loop->index }}"
                 class="rounded-[10px] border border-border border-l-[3px] {{ $borderAccent }} bg-card p-3.5 sm:p-4 flex flex-col justify-between gap-2.5 hover:border-border-strong transition-colors">
                <div class="space-y-1.5">
                    <h3 class="text-xs sm:text-[13px] font-semibold text-foreground leading-snug">
                        {{ $alert['title'] }}
                    </h3>
                    <div class="space-y-1 text-xs leading-relaxed">
                        <p class="text-foreground">
                            <span class="font-semibold text-muted-foreground">What happened:</span>
                            {{ $alert['what'] ?? ($alert['description'] ?? '') }}
                        </p>
                        @if(!empty($alert['why']))
                            <p class="text-muted-foreground text-[11px]">
                                <span class="font-semibold">Why it matters:</span>
                                {{ $alert['why'] }}
                            </p>
                        @endif
                    </div>
                </div>

                <div class="pt-2 border-t border-border flex items-center justify-between text-xs">
                    <span class="text-[10.5px] text-muted-foreground">Operational Telemetry</span>
                    @if(!empty($alert['action_url']))
                        <a href="{{ $alert['action_url'] }}" wire:navigate
                           class="font-semibold text-foreground hover:underline inline-flex items-center gap-1 text-[11px]">
                            <span>{{ $alert['action_text'] ?? ($alert['action_label'] ?? 'Inspect Issue') }}</span>
                            <span class="material-symbols-rounded text-sm">arrow_forward</span>
                        </a>
                    @endif
                </div>
            </div>
        @empty
            <div class="col-span-1 md:col-span-2 rounded-[10px] border border-dashed border-border p-6 text-center text-xs text-muted-foreground">
                All operating signals normal. No critical inventory buffers or float discrepancies detected.
            </div>
        @endforelse
    </div>
</section>
