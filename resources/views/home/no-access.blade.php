<x-app-layout>
    <div class="flex h-full flex-col items-center justify-center text-center px-6 py-16">
        <div class="w-16 h-16 rounded-2xl bg-muted flex items-center justify-center mb-5">
            <span class="material-symbols-rounded text-[32px] text-muted-foreground">lock_person</span>
        </div>
        <h1 class="text-xl font-bold text-foreground">No Modules Assigned to Your Role</h1>
        <p class="mt-2 max-w-sm text-sm text-muted-foreground">
            The role <strong>{{ auth()->user()->role?->name ?? '—' }}</strong> has not been mapped to any application views.
            Please contact your organization administrator to configure access permissions.
        </p>
        <form method="POST" action="{{ route('logout') }}" class="mt-6">
            @csrf
            <button type="submit"
                class="inline-flex items-center gap-2 rounded-xl border border-border bg-card px-5 py-2.5 text-sm font-semibold text-foreground hover:bg-muted transition-all active:scale-95 shadow-sm">
                <span class="material-symbols-rounded text-[20px]">logout</span> Log Out
            </button>
        </form>
    </div>
</x-app-layout>
