<x-app-layout>
    <div class="flex flex-col gap-6">
        <div>
            <h1 class="text-2xl font-bold text-yovel-ink tracking-tight">Audit Log</h1>
            <p class="text-sm font-medium text-[#787774] mt-1">Lacak dan pantau semua aktivitas pengguna di sistem Anda.</p>
        </div>

        <div class="card-surface bg-white rounded-2xl border border-yovel-border shadow-sm overflow-hidden">
            <div class="overflow-x-auto">
                <x-data-table>
                    <x-slot:head>
                        <th scope="col" class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider">Waktu</th>
                        <th scope="col" class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider">Pengguna</th>
                        <th scope="col" class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider">Aktivitas</th>
                        <th scope="col" class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider">Deskripsi</th>
                        <th scope="col" class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider">IP / Browser</th>
                    </x-slot:head>
                        @forelse ($logs as $log)
                            <tr class="hover:bg-[#F9F9F8] transition-colors">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="font-medium">{{ $log->created_at->format('d M Y') }}</div>
                                    <div class="text-xs text-[#787774]">{{ $log->created_at->format('H:i:s') }}</div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="font-bold flex items-center gap-2">
                                        <div class="w-7 h-7 rounded-full bg-primary-100 text-primary-700 flex items-center justify-center font-bold text-xs">
                                            {{ substr($log->user->name ?? 'S', 0, 1) }}
                                        </div>
                                        {{ $log->user->name ?? 'System' }}
                                    </div>
                                    <div class="text-xs text-[#787774] ml-9">{{ $log->user->role->name ?? '-' }}</div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-yovel-bg border border-yovel-border text-xs font-bold tracking-wide uppercase text-yovel-ink">
                                        {{ $log->action }}
                                    </span>
                                </td>
                                <td class="px-6 py-4">
                                    <p class="font-medium">{{ $log->description }}</p>
                                    @if($log->subject_type)
                                        <p class="text-xs text-[#787774] mt-0.5">Target: {{ class_basename($log->subject_type) }} #{{ $log->subject_id }}</p>
                                    @endif
                                </td>
                                <td class="px-6 py-4 text-xs text-[#787774]">
                                    <div class="font-mono">{{ $log->ip_address }}</div>
                                    <div class="truncate max-w-[150px]" title="{{ $log->user_agent }}">{{ Str::limit($log->user_agent, 20) }}</div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-6 py-8 text-center text-[#787774]">
                                    <span class="material-symbols-rounded text-[48px] mb-2 text-[#E9E9E7]">manage_search</span>
                                    <p>Belum ada catatan aktivitas.</p>
                                </td>
                            </tr>
                    @endforelse
                </x-data-table>
            </div>
            
            @if($logs->hasPages())
                <div class="p-4 border-t border-yovel-border shrink-0">
                    {{ $logs->links() }}
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
