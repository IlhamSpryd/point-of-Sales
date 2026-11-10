{{-- Kiosk Attendance Clock-in/out Modal for POS Terminal --}}
<div x-data="{
    openKiosk: false,
    pin: '',
    appendPin(digit) {
        if (this.pin.length < 6) {
            this.pin += digit;
        }
    },
    clearPin() {
        this.pin = '';
    },
    deletePin() {
        this.pin = this.pin.slice(0, -1);
    }
}"
@open-kiosk-attendance.window="openKiosk = true; pin = '';">
    <x-ui.modal x-model="openKiosk" title="Cashier Attendance Kiosk" maxWidth="sm">
        <div class="space-y-6 text-center">
            <div>
                <p class="text-xs text-muted-foreground mb-3">Enter your 6-digit staff PIN:</p>
                <div class="flex items-center justify-center gap-2">
                    <template x-for="i in 6">
                        <div class="w-4 h-4 rounded-full border-2 transition-all duration-150"
                            :class="pin.length >= i ? 'bg-primary border-primary scale-110' : 'border-border bg-muted'">
                        </div>
                    </template>
                </div>
            </div>

            <!-- Virtual Keypad Numeric -->
            <div class="grid grid-cols-3 gap-2.5 max-w-[240px] mx-auto">
                <template x-for="n in [1, 2, 3, 4, 5, 6, 7, 8, 9]">
                    <button type="button" @click="appendPin(n.toString())"
                        class="h-12 rounded-2xl bg-muted hover:bg-secondary hover:text-foreground text-foreground text-lg font-bold transition-colors cursor-pointer border border-border shadow-2xs flex items-center justify-center">
                        <span x-text="n"></span>
                    </button>
                </template>
                <button type="button" @click="clearPin()"
                    class="h-12 rounded-2xl bg-muted hover:bg-secondary text-muted-foreground text-xs font-bold transition-colors cursor-pointer border border-border shadow-2xs flex items-center justify-center">
                    CLEAR
                </button>
                <button type="button" @click="appendPin('0')"
                    class="h-12 rounded-2xl bg-muted hover:bg-secondary hover:text-foreground text-foreground text-lg font-bold transition-colors cursor-pointer border border-border shadow-2xs flex items-center justify-center">
                    0
                </button>
                <button type="button" @click="deletePin()"
                    class="h-12 rounded-2xl bg-muted hover:bg-secondary text-muted-foreground transition-colors cursor-pointer border border-border shadow-2xs flex items-center justify-center">
                    <span class="material-symbols-rounded text-xl">backspace</span>
                </button>
            </div>

            <!-- Actions -->
            <div class="grid grid-cols-2 gap-2 pt-2 border-t border-border" x-show="pin.length === 6">
                <button type="button" @click="$dispatch('kiosk-clock-in', { pin }); openKiosk = false;"
                    class="py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-xs rounded-xl transition-colors cursor-pointer shadow-xs">
                    Clock In
                </button>
                <button type="button" @click="$dispatch('kiosk-clock-out', { pin }); openKiosk = false;"
                    class="py-2.5 bg-destructive hover:bg-destructive/90 text-destructive-foreground font-bold text-xs rounded-xl transition-colors cursor-pointer shadow-xs">
                    Clock Out
                </button>
            </div>
        </div>
    </x-ui.modal>
</div>