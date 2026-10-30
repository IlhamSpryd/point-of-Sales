<x-app-layout>
    <div class="space-y-6 max-w-5xl mx-auto" data-gsap="page-enter">
        <x-erp.page-header
            title="System Settings"
            description="Configure Point of Sales preferences, enterprise features, primary store profile, and transaction rounding rules.">
            <x-slot:breadcrumbs>
                <x-ui.breadcrumbs.item href="{{ route('dashboard') }}">Dashboard</x-ui.breadcrumbs.item>
                <x-ui.breadcrumbs.item active>Settings</x-ui.breadcrumbs.item>
            </x-slot:breadcrumbs>
        </x-erp.page-header>

        @if (session('success'))
            <x-ui.alert type="success" title="Success" class="rounded-2xl">
                {{ session('success') }}
            </x-ui.alert>
        @endif

        <div class="card-surface rounded-2xl border border-[#E5E7EB] dark:border-zinc-800 p-6 shadow-xs">
            <form action="{{ route('settings.update') }}" method="POST" class="space-y-6">
                @csrf

                <div>
                    <h2 class="text-base font-bold text-zinc-900 dark:text-white mb-4 flex items-center gap-2">
                        <span class="material-symbols-rounded text-apeiron-primary text-xl">store</span>
                        Primary Store / Branch Information
                    </h2>
                    <div class="space-y-4">
                        <div>
                            <x-ui.label for="store_name">Store Name</x-ui.label>
                            <x-ui.input type="text" id="store_name" name="store_name" value="{{ old('store_name', $settings['store_name'] ?? '') }}" />
                        </div>

                        <div>
                            <x-ui.label for="store_address">Full Address</x-ui.label>
                            <textarea id="store_address" rows="3" name="store_address" class="w-full rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-4 py-2.5 text-sm focus:outline-hidden focus:ring-2 focus:ring-apeiron-primary/20 text-zinc-800 dark:text-zinc-200">{{ old('store_address', $settings['store_address'] ?? '') }}</textarea>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <x-ui.label for="store_phone">Phone Number</x-ui.label>
                                <x-ui.input type="text" id="store_phone" name="store_phone" value="{{ old('store_phone', $settings['store_phone'] ?? '') }}" />
                            </div>
                            <div>
                                <x-ui.label for="store_email">Email Address</x-ui.label>
                                <x-ui.input type="email" id="store_email" name="store_email" value="{{ old('store_email', $settings['store_email'] ?? '') }}" />
                            </div>
                        </div>
                    </div>
                </div>

                <div class="pt-6 border-t border-zinc-200 dark:border-zinc-800">
                    <h2 class="text-base font-bold text-zinc-900 dark:text-white mb-4 flex items-center gap-2">
                        <span class="material-symbols-rounded text-apeiron-primary text-xl">calculate</span>
                        Tax & Cashier Rounding Rules
                    </h2>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <x-ui.label for="tax_rate">Tax Rate (decimal, e.g., 0.11)</x-ui.label>
                            <x-ui.input type="number" step="0.01" min="0" max="1" id="tax_rate" name="tax_rate" value="{{ old('tax_rate', $settings['tax_rate'] ?? 0.11) }}" class="font-mono" />
                        </div>
                        <div>
                            <x-ui.label for="rounding_value">Rounding Multiple (e.g., 100)</x-ui.label>
                            <x-ui.input type="number" step="1" min="1" id="rounding_value" name="rounding_value" value="{{ old('rounding_value', $settings['rounding_value'] ?? 100) }}" class="font-mono" />
                        </div>
                        <div>
                            <x-ui.label for="rounding_behavior">Rounding Method</x-ui.label>
                            <x-ui.select id="rounding_behavior" name="rounding_behavior">
                                <option value="ROUND_NEAREST" @selected(($settings['rounding_behavior'] ?? 'ROUND_NEAREST') === 'ROUND_NEAREST')>Nearest (ROUND_NEAREST)</option>
                                <option value="ROUND_UP" @selected(($settings['rounding_behavior'] ?? '') === 'ROUND_UP')>Round Up (ROUND_UP)</option>
                                <option value="ROUND_DOWN" @selected(($settings['rounding_behavior'] ?? '') === 'ROUND_DOWN')>Round Down (ROUND_DOWN)</option>
                            </x-ui.select>
                        </div>
                    </div>
                </div>

                <div class="pt-4 border-t border-zinc-200 dark:border-zinc-800 flex justify-end">
                    <x-ui.button type="submit" variant="primary" class="gap-2">
                        <span class="material-symbols-rounded text-base">save</span>
                        Save Settings
                    </x-ui.button>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
