@props([
    'type' => 'Document Approval',
    'docNumber' => '',
    'requestedBy' => '',
    'role' => '',
    'amount' => null,
    'priority' => 'normal',
    'level' => 'Level 1',
    'reason' => null,
    'date' => null,
])

@php
    $priorityConfig = match (strtolower((string) $priority)) {
        'urgent', 'kritis' => [
            'label' => 'Urgent',
            'class' => 'bg-destructive-muted text-destructive border-destructive-border',
            'dot' => 'bg-destructive animate-pulse',
        ],
        'high', 'tinggi' => [
            'label' => 'High Priority',
            'class' => 'bg-warning-muted text-warning border-warning-border',
            'dot' => 'bg-warning',
        ],
        default => [
            'label' => 'Normal',
            'class' => 'bg-muted text-muted-foreground border-border',
            'dot' => 'bg-muted-foreground',
        ],
    };
@endphp

<div {{ $attributes->twMerge('rounded-2xl border border-border bg-card p-5 shadow-xs transition-all hover:border-border select-none space-y-3') }}>
    {{-- Card Header: Type, Doc No & Priority --}}
    <div class="flex items-start justify-between gap-3">
        <div>
            <div class="flex items-center gap-2">
                <span class="text-xs font-bold text-foreground">
                    {{ $type }}
                </span>
                <span class="text-xs font-mono font-bold text-muted-foreground">
                    {{ $docNumber }}
                </span>
            </div>
            <p class="text-xs text-muted-foreground mt-0.5">
                Requested by <strong class="text-foreground">{{ $requestedBy }}</strong>@if($role) ({{ $role }})@endif
            </p>
        </div>

        <div class="flex items-center gap-2">
            <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full border text-[10px] font-bold {{ $priorityConfig['class'] }}">
                <span class="size-1.5 rounded-full {{ $priorityConfig['dot'] }}" aria-hidden="true"></span>
                <span>{{ $priorityConfig['label'] }}</span>
            </span>
            <span class="px-2 py-0.5 rounded-md text-[10px] font-bold bg-secondary text-primary border border-border">
                {{ $level }}
            </span>
        </div>
    </div>

    {{-- Value and Reason Summary --}}
    <div class="bg-muted p-3 rounded-xl border border-border flex flex-col sm:flex-row sm:items-center justify-between gap-2 text-xs">
        @if ($reason)
            <div class="min-w-0 flex-1">
                <span class="text-[10px] uppercase font-bold text-muted-foreground block">Remarks / Justification:</span>
                <p class="text-foreground font-medium truncate mt-0.5">{{ $reason }}</p>
            </div>
        @endif

        @if ($amount)
            <div class="text-right sm:self-center shrink-0">
                <span class="text-[10px] uppercase font-bold text-muted-foreground block">Requested Amount:</span>
                <span class="text-base font-black text-foreground numeric font-mono">
                    {{ $amount }}
                </span>
            </div>
        @endif
    </div>

    {{-- Footer Actions: Approve & Reject --}}
    <div class="pt-2 border-t border-border flex items-center justify-between text-xs">
        <span class="text-[11px] text-muted-foreground flex items-center gap-1">
            <span class="material-symbols-rounded text-sm">schedule</span>
            <span>{{ $date ?? 'Pending Approval' }}</span>
        </span>

        <div class="flex items-center gap-2">
            @if (isset($actions))
                {{ $actions }}
            @else
                <button type="button"
                    class="px-3 py-1.5 rounded-lg text-xs font-semibold bg-destructive-muted text-destructive hover:bg-destructive-muted border border-destructive-border transition-colors cursor-pointer flex items-center gap-1">
                    <span class="material-symbols-rounded text-sm">close</span>
                    <span>Reject</span>
                </button>
                <button type="button"
                    class="px-3 py-1.5 rounded-lg text-xs font-semibold bg-success text-success-foreground hover:opacity-90 transition-colors cursor-pointer flex items-center gap-1">
                    <span class="material-symbols-rounded text-sm">check</span>
                    <span>Approve</span>
                </button>
            @endif
        </div>
    </div>
</div>
