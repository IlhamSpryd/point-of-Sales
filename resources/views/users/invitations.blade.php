<x-app-layout>
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
        <div>
            <h4 class="text-xl font-bold text-apeiron-ink tracking-tight">Undangan Karyawan</h4>
            <p class="text-sm font-medium text-[#787774] mt-1">Kelola dan pantau status undangan staf</p>
        </div>

        <div class="flex items-center gap-3">
            <a href="{{ route('users.index') }}" class="px-4 py-2 bg-white hover:bg-[#F7F7F5] border border-[#E9E9E7] text-apeiron-ink text-sm font-medium rounded-xl transition-all shadow-sm" wire:navigate>
                <span class="material-symbols-rounded text-[18px] align-middle mr-1">group</span> Daftar Pengguna
            </a>
            <a href="{{ route('users.create') }}" class="px-4 py-2 bg-apeiron-ink hover:bg-black text-white text-sm font-medium rounded-xl transition-all shadow-sm" wire:navigate>
                <span class="material-symbols-rounded text-[18px] align-middle mr-1">add</span> Undangan Baru
            </a>
        </div>
    </div>

    @if(session('success'))
        <x-alert type="success">{{ session('success') }}</x-alert>
    @endif

    @if(session('error'))
        <x-alert type="error">{{ session('error') }}</x-alert>
    @endif

    <div class="card-surface flex flex-col flex-1 min-h-0">
        <x-data-table>
            <x-slot:head>
                <th scope="col" class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider">Nama Calon Staf</th>
                <th scope="col" class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider">Email</th>
                <th scope="col" class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider">Peran & Cabang</th>
                <th scope="col" class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider">Status</th>
                <th scope="col" class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider">Berlaku Hingga</th>
                <th scope="col" class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider text-right">Aksi</th>
            </x-slot:head>

            @forelse($invitations as $invitation)
                <tr class="hover:bg-[#F7F7F5] transition-colors duration-200">
                    <td class="px-6 py-4 font-medium text-apeiron-ink text-sm">{{ $invitation->name }}</td>
                    <td class="px-6 py-4 text-sm text-[#787774]">{{ $invitation->email }}</td>
                    <td class="px-6 py-4 text-sm">
                        <div class="flex flex-col gap-1 items-start">
                            <x-badge type="info">{{ $invitation->role->name ?? 'Staff' }}</x-badge>
                            <span class="text-xs text-[#787774]">
                                {{ $invitation->stores->pluck('name')->join(', ') ?: 'Semua Cabang' }}
                            </span>
                        </div>
                    </td>
                    <td class="px-6 py-4">
                        @if($invitation->status === 'pending')
                            <x-badge type="warning">Menunggu Konfirmasi</x-badge>
                        @elseif($invitation->status === 'accepted')
                            <x-badge type="success">Diterima</x-badge>
                        @elseif($invitation->status === 'revoked')
                            <x-badge type="danger">Dicabut</x-badge>
                        @elseif($invitation->status === 'superseded')
                            <x-badge type="secondary">Digantikan</x-badge>
                        @else
                            <x-badge type="secondary">{{ ucfirst($invitation->status) }}</x-badge>
                        @endif
                    </td>
                    <td class="px-6 py-4 text-sm text-[#787774]">
                        {{ $invitation->expires_at ? $invitation->expires_at->format('M d, Y H:i') : '—' }}
                    </td>
                    <td class="px-6 py-4 text-right space-x-2">
                        @if($invitation->status === 'pending' && $invitation->expires_at->isFuture())
                            <form action="{{ route('users.invitations.resend', $invitation->id) }}" method="POST" class="inline-block">
                                @csrf
                                <button type="submit" class="text-xs font-medium text-primary-600 hover:text-primary-800 p-1.5 rounded-lg hover:bg-primary-50 transition-colors" title="Kirim Ulang">
                                    <span class="material-symbols-rounded text-sm align-middle">forward_to_inbox</span> Kirim Ulang
                                </button>
                            </form>
                            <form action="{{ route('users.invitations.revoke', $invitation->id) }}" method="POST" class="inline-block" onsubmit="return confirm('Apakah Anda yakin ingin mencabut undangan ini?')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="text-xs font-medium text-rose-600 hover:text-rose-800 p-1.5 rounded-lg hover:bg-rose-50 transition-colors" title="Cabut Undangan">
                                    <span class="material-symbols-rounded text-sm align-middle">cancel</span> Cabut
                                </button>
                            </form>
                        @else
                            <span class="text-xs text-[#9B9A97]">Selesai</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="px-6 py-12 text-center text-[#9B9A97] text-sm">
                        Belum ada undangan staf yang diterbitkan.
                    </td>
                </tr>
            @endforelse
        </x-data-table>

        <div class="p-4 border-t border-[#E9E9E7]">
            {{ $invitations->links() }}
        </div>
    </div>
</x-app-layout>
