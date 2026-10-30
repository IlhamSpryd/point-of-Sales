<x-app-layout>
    <div class="space-y-6" data-gsap="page-enter">
        <x-erp.page-header
            title="Activity Logs &amp; Security Audit"
            description="Track and monitor all user activity, authentication events, and data changes across your enterprise system.">
            <x-slot:breadcrumbs>
                <x-ui.breadcrumbs.item href="{{ route('dashboard') }}">Dashboard</x-ui.breadcrumbs.item>
                <x-ui.breadcrumbs.item active>Audit Logs</x-ui.breadcrumbs.item>
            </x-slot:breadcrumbs>
        </x-erp.page-header>

        <div class="card-surface rounded-xl border border-[#E5E7EB] dark:border-zinc-800 shadow-xs overflow-hidden">
            <div class="overflow-x-auto">
                <table class="table-standard w-full min-w-full text-left border-collapse text-sm">
                    <thead>
                        <tr class="border-b border-[#E5E7EB] dark:border-zinc-800 bg-zinc-50/50 dark:bg-zinc-900/50 text-xs font-semibold text-zinc-500 uppercase tracking-wider">
                            <th class="px-6 py-4">Timestamp</th>
                            <th class="px-6 py-4">User</th>
                            <th class="px-6 py-4">Action</th>
                            <th class="px-6 py-4">Description</th>
                            <th class="px-6 py-4">IP / Device</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[#E5E7EB] dark:divide-zinc-800/70">
                        @forelse ($logs as $log)
                            <tr class="hover:bg-zinc-50/60 dark:hover:bg-zinc-800/40 transition">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="font-medium text-zinc-900 dark:text-white text-xs">{{ $log->created_at->format('M d, Y') }}</div>
                                    <div class="text-[11px] text-zinc-400 font-mono">{{ $log->created_at->format('H:i:s') }}</div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="font-bold flex items-center gap-2 text-zinc-900 dark:text-white text-xs">
                                        <div class="w-7 h-7 rounded-full bg-apeiron-primary/10 text-apeiron-primary flex items-center justify-center font-bold text-xs uppercase">
                                            {{ substr($log->user->name ?? 'S', 0, 1) }}
                                        </div>
                                        {{ $log->user->name ?? 'System' }}
                                    </div>
                                    <div class="text-[11px] text-zinc-400 ml-9">{{ $log->user->role->name ?? '-' }}</div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <x-ui.badge variant="neutral" size="xs">
                                        {{ $log->action }}
                                    </x-ui.badge>
                                </td>
                                <td class="px-6 py-4">
                                    <p class="font-medium text-xs text-zinc-800 dark:text-zinc-200">{{ $log->description }}</p>
                                    @if($log->subject_type)
                                        <p class="text-[11px] text-zinc-400 mt-0.5">Target: {{ class_basename($log->subject_type) }} #{{ $log->subject_id }}</p>
                                    @endif
                                </td>
                                <td class="px-6 py-4 text-xs text-zinc-500">
                                    <div class="font-mono text-zinc-600 dark:text-zinc-300">{{ $log->ip_address }}</div>
                                    <div class="truncate max-w-[150px] text-[11px]" title="{{ $log->user_agent }}">{{ Str::limit($log->user_agent, 20) }}</div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-6 py-12 text-center text-zinc-400 text-sm">
                                    <span class="material-symbols-rounded text-4xl mb-2 block">manage_search</span>
                                    No activity logs recorded yet.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            
            @if($logs->hasPages())
                <div class="p-4 border-t border-[#E5E7EB] dark:border-zinc-800">
                    {{ $logs->links() }}
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
