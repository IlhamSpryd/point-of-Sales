<div class="py-6 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
    <x-erp.module-sub-nav module="organization" />

    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-foreground tracking-tight">
                SaaS Subscription & Quota Portal
            </h1>
            <p class="text-sm text-muted-foreground mt-1">
                Overview of active subscription tier, resource quota utilization, and plan entitlements.
            </p>
        </div>
        <div class="flex items-center gap-2">
            <button wire:click="$set('isUpgradeModalOpen', true)"
                class="inline-flex items-center gap-2 px-4 py-2 bg-primary hover:bg-primary/90 text-primary-foreground text-sm font-semibold rounded-xl shadow-sm transition-colors">
                <span class="material-symbols-rounded text-[20px]">upgrade</span>
                Upgrade Plan
            </button>
        </div>
    </div>

    @if (session()->has('message'))
        <div class="p-4 bg-emerald-50 border border-emerald-200 rounded-xl flex items-center gap-3 text-emerald-800 text-sm font-medium">
            <span class="material-symbols-rounded text-emerald-600 text-[20px]">check_circle</span>
            {{ session('message') }}
        </div>
    @endif

    @if ($pendingUpgradeRequest)
        <div class="p-4 bg-amber-500/10 border border-amber-500/30 rounded-2xl flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 text-amber-800 dark:text-amber-300 text-sm font-medium">
            <div class="flex items-center gap-3">
                <span class="material-symbols-rounded text-amber-600 dark:text-amber-400 text-[24px]">hourglass_top</span>
                <div>
                    <p class="font-bold">Permintaan Upgrade Paket Sedang Diproses</p>
                    <p class="text-xs text-muted-foreground mt-0.5">
                        Anda telah mengajukan permohonan upgrade ke paket <span class="font-semibold text-foreground">{{ $pendingUpgradeRequest->plan?->name }}</span>. Tim representatif kami sedang meninjau permintaan Anda.
                    </p>
                </div>
            </div>
            <span class="inline-flex self-start sm:self-auto px-2.5 py-1 text-xs font-bold rounded-lg bg-amber-500/20 text-amber-700 dark:text-amber-300 uppercase tracking-wide">
                {{ $pendingUpgradeRequest->status }}
            </span>
        </div>
    @endif

    <!-- Active Plan Summary Card -->
    <div class="bg-card rounded-2xl border border-border p-6 shadow-sm space-y-4">
        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
            <div>
                <span class="text-xs font-semibold uppercase tracking-wider text-primary">Active Plan</span>
                <h2 class="text-2xl font-extrabold text-foreground mt-1">{{ $activePlan?->name ?? 'Starter Tier' }}</h2>
                <p class="text-xs text-muted-foreground mt-0.5">Billing Status: <span class="font-semibold text-emerald-600 uppercase">{{ $subscription?->status ?? 'ACTIVE' }}</span></p>
            </div>
            <div class="text-right">
                <span class="text-xs text-muted-foreground">Monthly Fee</span>
                <p class="text-xl font-bold text-foreground">Rp {{ number_format((float) ($activePlan?->price ?? 0), 0, ',', '.') }}<span class="text-xs text-muted-foreground font-normal"> / mo</span></p>
            </div>
        </div>
    </div>

    <!-- Usage Quota Meters Grid -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        <!-- Stores Meter -->
        <div class="bg-card rounded-2xl border border-border p-5 shadow-sm space-y-3">
            <div class="flex justify-between items-center">
                <span class="text-sm font-semibold text-foreground flex items-center gap-2">
                    <span class="material-symbols-rounded text-primary text-[20px]">storefront</span>
                    Physical Stores
                </span>
                <span class="text-xs font-bold text-foreground">{{ $storeUsage['used'] }} / {{ $storeUsage['limit'] >= 1000 ? 'Unlimited' : $storeUsage['limit'] }}</span>
            </div>
            <div class="w-full bg-muted rounded-full h-2.5 overflow-hidden">
                @php
                    $pct = $storeUsage['limit'] > 0 && $storeUsage['limit'] < 1000 ? min(100, round(($storeUsage['used'] / $storeUsage['limit']) * 100)) : 15;
                @endphp
                <div class="bg-primary h-2.5 rounded-full" style="width: {{ $pct }}%"></div>
            </div>
            <p class="text-xs text-muted-foreground">Outlets & physical operational branches.</p>
        </div>

        <!-- POS Devices Meter -->
        <div class="bg-card rounded-2xl border border-border p-5 shadow-sm space-y-3">
            <div class="flex justify-between items-center">
                <span class="text-sm font-semibold text-foreground flex items-center gap-2">
                    <span class="material-symbols-rounded text-primary text-[20px]">point_of_sale</span>
                    POS Registers
                </span>
                <span class="text-xs font-bold text-foreground">{{ $deviceUsage['used'] }} / {{ $deviceUsage['limit'] >= 1000 ? 'Unlimited' : $deviceUsage['limit'] }}</span>
            </div>
            <div class="w-full bg-muted rounded-full h-2.5 overflow-hidden">
                @php
                    $pct = $deviceUsage['limit'] > 0 && $deviceUsage['limit'] < 1000 ? min(100, round(($deviceUsage['used'] / $deviceUsage['limit']) * 100)) : 20;
                @endphp
                <div class="bg-primary h-2.5 rounded-full" style="width: {{ $pct }}%"></div>
            </div>
            <p class="text-xs text-muted-foreground">Active cashier machines and frontline terminals.</p>
        </div>

        <!-- Users Meter -->
        <div class="bg-card rounded-2xl border border-border p-5 shadow-sm space-y-3">
            <div class="flex justify-between items-center">
                <span class="text-sm font-semibold text-foreground flex items-center gap-2">
                    <span class="material-symbols-rounded text-primary text-[20px]">group</span>
                    Staff & Users
                </span>
                <span class="text-xs font-bold text-foreground">{{ $userUsage['used'] }} / {{ $userUsage['limit'] >= 1000 ? 'Unlimited' : $userUsage['limit'] }}</span>
            </div>
            <div class="w-full bg-muted rounded-full h-2.5 overflow-hidden">
                @php
                    $pct = $userUsage['limit'] > 0 && $userUsage['limit'] < 1000 ? min(100, round(($userUsage['used'] / $userUsage['limit']) * 100)) : 25;
                @endphp
                <div class="bg-primary h-2.5 rounded-full" style="width: {{ $pct }}%"></div>
            </div>
            <p class="text-xs text-muted-foreground">Employee logins and managerial accounts.</p>
        </div>
    </div>

    <!-- Upgrade Modal -->
    @if ($isUpgradeModalOpen)
        <x-ui.modal wire:model="isUpgradeModalOpen" title="Upgrade Subscription Tier" maxWidth="lg" onClose="$wire.set('isUpgradeModalOpen', false)">
            <div class="space-y-3">
                <label class="block text-xs font-semibold text-foreground">Select Target Tier</label>
                @foreach ($allPlans as $plan)
                    <div wire:click="$set('selectedPlanId', {{ $plan->id }})"
                        class="p-4 rounded-xl border cursor-pointer transition-all {{ $selectedPlanId === $plan->id ? 'border-primary bg-primary/5 ring-2 ring-primary/20' : 'border-border bg-card hover:border-primary/50' }}">
                        <div class="flex justify-between items-center">
                            <div>
                                <h4 class="font-bold text-foreground text-sm">{{ $plan->name }}</h4>
                                <p class="text-xs text-muted-foreground">{{ $plan->description ?? 'Enterprise-grade features' }}</p>
                            </div>
                            <span class="text-sm font-bold text-foreground">Rp {{ number_format((float) $plan->price, 0, ',', '.') }}/mo</span>
                        </div>
                    </div>
                @endforeach
            </div>

            <x-slot:footer>
                <div class="flex justify-end gap-2 w-full">
                    <button type="button" wire:click="$set('isUpgradeModalOpen', false)" class="px-4 py-2 text-muted-foreground hover:text-foreground rounded-xl text-sm font-medium transition-colors">
                        Cancel
                    </button>
                    <button type="button" wire:click="requestPlanUpgrade" class="px-4 py-2 bg-primary text-primary-foreground rounded-xl text-sm font-semibold hover:bg-primary/90 transition-colors shadow-xs">
                        Request Upgrade
                    </button>
                </div>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
