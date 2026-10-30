{{-- Operational Context Banner --}}
@if(!$this->activeShift)
    <div class="bg-rose-600 text-white px-4 py-2 text-xs font-bold flex items-center justify-between shrink-0 shadow-sm z-40">
        <div class="flex items-center gap-2">
            <span class="material-symbols-rounded text-[18px]">lock</span>
            <span>POS Terminal Has No Open Shift: Cashier must open a shift before processing sales transactions.</span>
        </div>
        <a href="{{ route('shifts.index') }}" class="underline hover:text-white/80 bg-white/20 px-3 py-1 rounded-lg transition-colors">Open Cashier Shift</a>
    </div>
@elseif($this->activeShift->status === 'handover_pending')
    <div class="bg-amber-600 text-white px-4 py-2 text-xs font-bold flex items-center justify-between shrink-0 shadow-sm z-40">
        <div class="flex items-center gap-2">
            <span class="material-symbols-rounded text-[18px]">pending_actions</span>
            <span>Shift Handover in Progress: Confirm shift acceptance or cancel the handover to proceed.</span>
        </div>
        <a href="{{ route('shifts.index') }}" class="underline hover:text-white/80 bg-white/20 px-3 py-1 rounded-lg transition-colors">Manage Shifts</a>
    </div>
@endif
