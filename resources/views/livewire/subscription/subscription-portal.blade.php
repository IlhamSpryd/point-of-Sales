<div class="py-6 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
    <x-erp.module-sub-nav module="organization" />

    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-foreground tracking-tight flex items-center gap-2">
                <span class="material-symbols-rounded text-primary text-[28px]">workspace_premium</span>
                SaaS Subscription & Quota Portal
            </h1>
            <p class="text-sm text-muted-foreground mt-1">
                Overview of active subscription tier, resource quota utilization, and plan entitlements.
            </p>
        </div>
        <div class="flex items-center gap-2">
            <button wire:click="$set('isUpgradeModalOpen', true)"
                class="inline-flex items-center gap-2 px-4 py-2 bg-primary hover:bg-primary/90 text-primary-foreground text-sm font-semibold rounded-xl shadow-sm transition-colors">
                <span class="material-symbols-rounded text-[18px]">upgrade</span>
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
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm p-4">
            <div class="bg-card border border-border rounded-2xl max-w-lg w-full p-6 space-y-4 shadow-xl">
                <div class="flex items-center justify-between">
                    <h3 class="text-lg font-bold text-foreground">Upgrade Subscription Tier</h3>
                    <button wire:click="$set('isUpgradeModalOpen', false)" class="text-muted-foreground hover:text-foreground">
                        <span class="material-symbols-rounded">close</span>
                    </button>
                </div>
                <div class="space-y-3">
                    <label class="block text-xs font-semibold text-foreground">Select Target Tier</label>
                    @foreach ($allPlans as $plan)
                        <div wire:click="$set('selectedPlanId', {{ $plan->id }})"
                            class="p-4 rounded-xl border cursor-pointer transition-all {{ $selectedPlanId === $plan->id ? 'border-primary bg-primary/5 ring-2 ring-primary/20' : 'border-border bg-background hover:border-primary/50' }}">
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
                <div class="flex justify-end gap-2 pt-2">
                    <button wire:click="$set('isUpgradeModalOpen', false)" class="px-4 py-2 border border-border text-foreground rounded-xl text-sm font-medium hover:bg-muted">
                        Cancel
                    </button>
                    <button wire:click="requestPlanUpgrade" class="px-4 py-2 bg-primary text-primary-foreground rounded-xl text-sm font-semibold hover:bg-primary/90">
                        Request Upgrade
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
