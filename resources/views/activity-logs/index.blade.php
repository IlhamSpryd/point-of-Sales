<x-app-layout>
    <div class="space-y-6" data-gsap="page-enter">
        <x-erp.module-sub-nav module="governance" />

        <x-erp.page-header
            title="Activity Logs &amp; Security Audit"
            description="Track and monitor all user activity, authentication events, and data changes across your enterprise system.">
            <x-slot:breadcrumbs>
                <x-ui.breadcrumbs.item href="{{ route('erp.workflow.approvals') }}">Dashboard</x-ui.breadcrumbs.item>
                <x-ui.breadcrumbs.item active>Audit Logs</x-ui.breadcrumbs.item>
            </x-slot:breadcrumbs>
        </x-erp.page-header>

        <x-erp.data-table
            :is-empty="$logs->isEmpty()"
            empty-title="No activity logs recorded yet."
            empty-description="User actions and system audits will appear here."
            empty-icon="manage_search"
            :colspan="5"
        >
            <x-slot:head>
                <th scope="col" class="h-8 px-3 py-2 text-left text-[10.5px] font-semibold text-muted-foreground uppercase tracking-wider">Timestamp</th>
                <th scope="col" class="h-8 px-3 py-2 text-left text-[10.5px] font-semibold text-muted-foreground uppercase tracking-wider">User</th>
                <th scope="col" class="h-8 px-3 py-2 text-left text-[10.5px] font-semibold text-muted-foreground uppercase tracking-wider">Action</th>
                <th scope="col" class="h-8 px-3 py-2 text-left text-[10.5px] font-semibold text-muted-foreground uppercase tracking-wider">Description</th>
                <th scope="col" class="h-8 px-3 py-2 text-left text-[10.5px] font-semibold text-muted-foreground uppercase tracking-wider">IP / Device</th>
            </x-slot:head>

            @foreach ($logs as $log)
                <tr class="hover:bg-muted/40 transition-colors">
                    <td class="px-3 py-2 whitespace-nowrap">
                        <div class="font-medium text-foreground text-[11px]">{{ $log->created_at->format('M d, Y') }}</div>
                        <div class="text-[10px] text-muted-foreground font-sans">{{ $log->created_at->format('H:i:s') }}</div>
                    </td>
                    <td class="px-3 py-2 whitespace-nowrap">
                        <div class="font-bold flex items-center gap-2 text-foreground text-[11px]">
                            <div class="w-6 h-6 rounded-full bg-primary/10 text-primary flex items-center justify-center font-bold text-[10px] uppercase">
                                {{ substr($log->user->name ?? 'S', 0, 1) }}
                            </div>
                            {{ $log->user->name ?? 'System' }}
                        </div>
                        <div class="text-[10.5px] text-muted-foreground ml-8">{{ $log->user->role->name ?? '-' }}</div>
                    </td>
                    <td class="px-3 py-2 whitespace-nowrap">
                        <span class="text-[11px] text-muted-foreground font-medium font-sans">
                            {{ $log->action }}
                        </span>
                    </td>
                    <td class="px-3 py-2">
                        <p class="font-medium text-[11px] text-foreground">{{ $log->description }}</p>
                        @if($log->subject_type)
                            <p class="text-[10px] text-muted-foreground mt-0.5">Target: {{ class_basename($log->subject_type) }} #{{ $log->subject_id }}</p>
                        @endif
                    </td>
                    <td class="px-3 py-2 text-[11px] text-muted-foreground">
                        <div class="font-sans text-foreground">{{ $log->ip_address }}</div>
                        <div class="truncate max-w-[150px] text-[11px]" title="{{ $log->user_agent }}">{{ Str::limit($log->user_agent, 20) }}</div>
                    </td>
                </tr>
            @endforeach

            @if($logs->hasPages())
                <x-slot:pagination>
                    {{ $logs->links() }}
                </x-slot:pagination>
            @endif
        </x-erp.data-table>
    </div>
</x-app-layout>
