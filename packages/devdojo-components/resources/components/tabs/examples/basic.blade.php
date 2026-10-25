<div class="flex w-full justify-center">
    <div class="w-full max-w-md">
        <x-components.tabs>
            <x-components.tabs.list>
                <x-components.tabs.tab tab="account">Account</x-components.tabs.tab>
                <x-components.tabs.tab tab="password">Password</x-components.tabs.tab>
                <x-components.tabs.tab tab="team">Team</x-components.tabs.tab>
            </x-components.tabs.list>
            <x-components.tabs.panel tab="account">
                <x-components.card><p class="text-sm text-foreground/70">Manage your account details and preferences here.</p></x-components.card>
            </x-components.tabs.panel>
            <x-components.tabs.panel tab="password">
                <x-components.card><p class="text-sm text-foreground/70">Change your password — you'll be signed out afterwards.</p></x-components.card>
            </x-components.tabs.panel>
            <x-components.tabs.panel tab="team">
                <x-components.card><p class="text-sm text-foreground/70">Invite teammates and manage their roles.</p></x-components.card>
            </x-components.tabs.panel>
        </x-components.tabs>
    </div>
</div>
