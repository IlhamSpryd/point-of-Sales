{{--
    NOTIFICATION CENTER

    The bell intentionally starts EMPTY. The previous implementation shipped three
    hard-coded fake notifications ("Active Cashier Shift", "Cloud Synchronization",
    "Inventory Levels Verified") to every role, which erodes operator trust in the
    whole notification surface. Until a real notification source is wired in, the
    honest state is an empty state — never fabricated activity.
--}}
@props([
    'unreadCount' => null,
    'notifications' => null,
])

@php
    if ($notifications === null) {
        $realNotifications = [];
        $user = auth()->user();
        if ($user && $user->tenant_id) {
            $tId = (int) $user->tenant_id;
            $sId = $user->store_id ?? session('active_store_id');
            if ($sId === 'all') {
                $sId = null;
            }

            try {
                // 1. Stockout detection
                $stockoutCount = (int) \Illuminate\Support\Facades\DB::table('products')
                    ->where('tenant_id', $tId)
                    ->where('is_active', true)
                    ->where('stock', '<=', 0)
                    ->count();
                if ($stockoutCount > 0) {
                    $realNotifications[] = [
                        'id' => 'alert_stockout_' . $tId,
                        'icon' => 'inventory_2',
                        'variant' => 'amber',
                        'title' => 'Stok Habis Terdeteksi',
                        'desc' => "{$stockoutCount} SKU produk kehabisan stok dan memerlukan pengadaan/restock.",
                        'time' => 'Live',
                        'unread' => true,
                    ];
                }

                // 2. Open Cashier Shifts
                $shiftQ = \Illuminate\Support\Facades\DB::table('shifts')
                    ->where('tenant_id', $tId)
                    ->whereNull('closed_at');
                if ($sId) {
                    $shiftQ->where('store_id', (int) $sId);
                }
                $openShifts = (int) $shiftQ->count();
                if ($openShifts > 0) {
                    $realNotifications[] = [
                        'id' => 'alert_shift_' . $tId,
                        'icon' => 'schedule',
                        'variant' => 'blue',
                        'title' => 'Sesi Shift Kasir Aktif',
                        'desc' => "{$openShifts} shift kasir sedang berjalan menunggu rekonsiliasi EOD.",
                        'time' => 'Berjalan',
                        'unread' => false,
                    ];
                }

                // 3. Pending Void / Supervisor Overrides
                $pendingVoids = (int) \Illuminate\Support\Facades\DB::table('orders')
                    ->where('tenant_id', $tId)
                    ->where('order_status', 'void_pending')
                    ->count();
                if ($pendingVoids > 0) {
                    $realNotifications[] = [
                        'id' => 'alert_void_' . $tId,
                        'icon' => 'verified_user',
                        'variant' => 'amber',
                        'title' => 'Otorisasi Supervisor Menunggu',
                        'desc' => "{$pendingVoids} permintaan pembatalan pesanan memerlukan verifikasi PIN.",
                        'time' => 'Perhatian',
                        'unread' => true,
                    ];
                }
            } catch (\Throwable $e) {
                $realNotifications = [];
            }
        }
        $notifications = $realNotifications;
    } else {
        $notifications = (array) $notifications;
    }
@endphp

<div x-data="{
        open: false,
        activeTab: 'all',
        notifications: @js(array_values($notifications)),
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
        <span class="material-symbols-rounded text-[20px]" aria-hidden="true">notifications</span>

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
                        <span class="material-symbols-rounded text-[16px]" x-text="item.icon"></span>
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
                    View Complete Activity Log
                </a>
            </div>
        @endif
    </div>
</div>
