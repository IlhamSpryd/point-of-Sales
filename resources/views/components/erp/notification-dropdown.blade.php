@props([
    'unreadCount' => 0,
])

<div x-data="{
        open: false,
        activeTab: 'all',
        notifications: [
            { id: 1, title: 'Active Cashier Shift', desc: 'Cashier shift operating normally on Terminal 01', time: '10m ago', icon: 'point_of_sale', variant: 'emerald', unread: true },
            { id: 2, title: 'Cloud Synchronization', desc: 'Transaction batch synchronized successfully', time: '35m ago', icon: 'cloud_done', variant: 'blue', unread: true },
            { id: 3, title: 'Inventory Levels Verified', desc: 'Automated stock-on-hand audit completed', time: '2h ago', icon: 'inventory_2', variant: 'amber', unread: false }
        ],
        markAllRead() {
            this.notifications.forEach(n => n.unread = false);
        },
        get unreadTotal() {
            return this.notifications.filter(n => n.unread).length;
        },
        get filtered() {
            if (this.activeTab === 'unread') {
                return this.notifications.filter(n => n.unread);
            }
            return this.notifications;
        }
    }"
    class="relative"
    {{ $attributes->twMerge('inline-block') }}>

    {{-- Notification Trigger Button --}}
    <button type="button"
        @click="open = !open"
        :aria-expanded="open.toString()"
        aria-haspopup="true"
        aria-label="System notifications"
        class="relative w-8 h-8 flex items-center justify-center rounded-lg text-shell-foreground bg-shell-accent hover:bg-shell-border hover:text-shell-foreground-active border border-shell-border active:scale-95 transition-all cursor-pointer">
        <span class="material-symbols-rounded text-[18px]" aria-hidden="true">notifications</span>

        {{-- Unread Badge Dot --}}
        <template x-if="unreadTotal > 0">
            <span class="absolute top-1.5 right-1.5 flex h-2 w-2">
                <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-destructive opacity-75"></span>
                <span class="relative inline-flex rounded-full h-2 w-2 bg-destructive"></span>
            </span>
        </template>
    </button>

    {{-- Dropdown Popover (TailAdmin Style) --}}
    <div x-show="open"
        x-cloak
        @click.outside="open = false"
        @keydown.escape.window="open = false"
        x-transition:enter="transition ease-out duration-150"
        x-transition:enter-start="opacity-0 scale-95 translate-y-1"
        x-transition:enter-end="opacity-100 scale-100 translate-y-0"
        x-transition:leave="transition ease-in duration-100"
        x-transition:leave-start="opacity-100 scale-100 translate-y-0"
        x-transition:leave-end="opacity-0 scale-95 translate-y-1"
        class="absolute right-0 mt-2 w-80 sm:w-96 rounded-xl bg-shell border border-shell-border shadow-2xl z-50 overflow-hidden text-xs text-shell-foreground">

        {{-- Popover Header --}}
        <div class="px-4 py-3 border-b border-shell-border flex items-center justify-between">
            <div class="flex items-center gap-2">
                <span class="text-xs font-semibold text-shell-foreground-active">Notifications</span>
                <template x-if="unreadTotal > 0">
                    <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-destructive-muted text-destructive border border-destructive-border" x-text="unreadTotal + ' New'"></span>
                </template>
            </div>
            <button type="button"
                @click="markAllRead()"
                class="text-[11px] font-medium text-shell-foreground hover:text-shell-foreground-active transition-colors cursor-pointer">
                Mark all as read
            </button>
        </div>

        {{-- Segmented Pill Tabs (TailAdmin Tab Style) --}}
        <div class="px-3 pt-2.5 pb-1 flex items-center gap-1.5 bg-shell border-b border-shell-border">
            <button type="button"
                @click="activeTab = 'all'"
                :class="activeTab === 'all' ? 'bg-shell-accent text-shell-foreground-active font-semibold' : 'text-shell-foreground hover:text-shell-foreground-active'"
                class="px-2.5 py-1 rounded-md text-[11px] transition-all cursor-pointer">
                All
            </button>
            <button type="button"
                @click="activeTab = 'unread'"
                :class="activeTab === 'unread' ? 'bg-shell-accent text-shell-foreground-active font-semibold' : 'text-shell-foreground hover:text-shell-foreground-active'"
                class="px-2.5 py-1 rounded-md text-[11px] transition-all cursor-pointer flex items-center gap-1.5">
                <span>Unread</span>
                <template x-if="unreadTotal > 0">
                    <span class="w-1.5 h-1.5 rounded-full bg-destructive"></span>
                </template>
            </button>
        </div>

        {{-- Notifications List --}}
        <div class="divide-y divide-shell-border max-h-72 overflow-y-auto scrollbar-hide">
            <template x-for="item in filtered" :key="item.id">
                <div class="p-3 hover:bg-shell-accent transition-colors flex items-start gap-3 cursor-pointer group"
                    @click="item.unread = false">
                    {{-- Icon Container with Soft Pastel Accent --}}
                    <div class="w-8 h-8 rounded-lg shrink-0 flex items-center justify-center border"
                        :class="{
                            'bg-success-muted/40 text-success border-success-border/40': item.variant === 'emerald',
                            'bg-info-muted/40 text-info border-info-border/40': item.variant === 'blue',
                            'bg-warning-muted/40 text-warning border-warning-border/40': item.variant === 'amber'
                        }">
                        <span class="material-symbols-rounded text-[17px]" x-text="item.icon"></span>
                    </div>

                    {{-- Text Content --}}
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center justify-between gap-1">
                            <p class="text-xs font-semibold text-shell-foreground-active truncate" x-text="item.title"></p>
                            <span class="text-[10px] text-shell-foreground whitespace-nowrap" x-text="item.time"></span>
                        </div>
                        <p class="text-[11px] text-shell-foreground mt-0.5 line-clamp-2 leading-relaxed" x-text="item.desc"></p>
                    </div>

                    {{-- Unread Dot --}}
                    <template x-if="item.unread">
                        <span class="w-2 h-2 rounded-full bg-info mt-1 shrink-0"></span>
                    </template>
                </div>
            </template>

            {{-- Empty State --}}
            <template x-if="filtered.length === 0">
                <div class="py-8 px-4 text-center">
                    <span class="material-symbols-rounded text-2xl text-shell-border">notifications_off</span>
                    <p class="text-xs text-shell-foreground mt-1.5 font-medium">No new notifications</p>
                </div>
            </template>
        </div>

        {{-- Footer --}}
        @php
            $canViewAuditLogs = in_array(auth()->user()?->role?->name, ['Owner', 'Manager', 'Admin'], true)
                || (bool) auth()->user()?->hasPermission('audit_logs.view');
        @endphp
        @if ($canViewAuditLogs && \Illuminate\Support\Facades\Route::has('activity-logs.index'))
            <div class="p-2 border-t border-shell-border bg-shell text-center">
                <a href="{{ route('activity-logs.index') }}" wire:navigate
                    @click="open = false"
                    class="block py-1 text-[11px] font-medium text-shell-foreground hover:text-shell-foreground-active transition-colors">
                    View Complete Activity Log &rarr;
                </a>
            </div>
        @endif
    </div>
</div>
