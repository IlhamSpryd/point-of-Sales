{{-- ========================================================================= --}}
{{-- SECTION G: OPERATIONAL ALERTS & BUSINESS INSIGHTS (WHAT / WHY / ACTION)   --}}
{{-- ========================================================================= --}}
@php
    $alerts = $executiveData['operational_alerts'] ?? [];
@endphp

<section aria-label="Operational Alerts and Business Insights" class="space-y-4">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
        <div>
            <h2 class="text-sm sm:text-base font-semibold text-foreground">Operational Alerts &amp; Business Insights</h2>
            <p class="text-xs text-muted-foreground mt-0.5">
                Active exceptions detected across outlet sales velocity, inventory buffers, and purchasing workflows.
            </p>
        </div>
        <div class="text-xs font-semibold text-muted-foreground tabular-nums">
            {{ count($alerts) }} Monitored Insights
        </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        @forelse($alerts as $alert)
            @php
                $sev = $alert['severity'] ?? ($alert['type'] ?? 'neutral');
                $borderAccent = match($sev) {
                    'critical' => 'border-l-destructive',
                    'warning' => 'border-l-warning',
                    'positive' => 'border-l-success',
                    default => 'border-l-muted-foreground'
                };
            @endphp
            <div wire:key="alert-card-{{ $alert['id'] ?? $loop->index }}"
                 class="rounded-lg border border-border border-l-[3px] {{ $borderAccent }} bg-card/60 p-4 sm:p-5 flex flex-col justify-between gap-3 hover:border-border-strong transition-colors">
                <div class="space-y-2">
                    <h3 class="text-xs sm:text-sm font-semibold text-foreground leading-snug">
                        {{ $alert['title'] }}
                    </h3>
                    <div class="space-y-1 text-xs leading-relaxed">
                        <p class="text-foreground">
                            <span class="font-semibold text-muted-foreground">What happened:</span>
                            {{ $alert['what'] ?? ($alert['description'] ?? '') }}
                        </p>
                        @if(!empty($alert['why']))
                            <p class="text-muted-foreground">
                                <span class="font-semibold">Why it matters:</span>
                                {{ $alert['why'] }}
                            </p>
                        @endif
                    </div>
                </div>

                <div class="pt-2 border-t border-border flex items-center justify-between text-xs">
                    <span class="text-[11px] text-muted-foreground">Operational Telemetry</span>
                    @if(!empty($alert['action_url']))
                        <a href="{{ $alert['action_url'] }}" wire:navigate
                           class="font-semibold text-primary hover:underline inline-flex items-center gap-1">
                            <span>{{ $alert['action_text'] ?? ($alert['action_label'] ?? 'Inspect Issue') }}</span>
                            <span class="material-symbols-rounded text-sm">arrow_forward</span>
                        </a>
                    @endif
                </div>
            </div>
        @empty
            <div class="col-span-1 md:col-span-2 rounded-lg border border-dashed border-border p-8 text-center text-xs text-muted-foreground">
                All operating signals normal. No critical inventory buffers or float discrepancies detected.
            </div>
        @endforelse
    </div>
</section>

