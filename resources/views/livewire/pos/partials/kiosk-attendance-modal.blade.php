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
@open-kiosk-attendance.window="openKiosk = true; pin = '';"
x-show="openKiosk"
class="fixed inset-0 z-50 overflow-y-auto bg-black/60 backdrop-blur-xs flex items-center justify-center p-4"
x-cloak>
    <div class="bg-white rounded-3xl max-w-sm w-full p-6 shadow-2xl space-y-6 text-center" @click.away="openKiosk = false">
        <div class="flex items-center justify-between border-b border-gray-100 pb-3">
            <h3 class="text-base font-bold text-gray-900 flex items-center gap-2">
                <span class="material-symbols-rounded text-indigo-600">fingerprint</span>
                Cashier Attendance Kiosk
            </h3>
            <button @click="openKiosk = false" class="text-gray-400 hover:text-gray-600 cursor-pointer">
                <span class="material-symbols-rounded">close</span>
            </button>
        </div>

        <div>
            <p class="text-xs text-gray-500 mb-3">Enter your 6-digit staff PIN:</p>
            <div class="flex items-center justify-center gap-2">
                <template x-for="i in 6">
                    <div class="w-4 h-4 rounded-full border-2 transition-all duration-150"
                        :class="pin.length >= i ? 'bg-indigo-600 border-indigo-600 scale-110' : 'border-gray-300 bg-gray-50'">
                    </div>
                </template>
            </div>
        </div>

        <!-- Virtual Keypad Numeric -->
        <div class="grid grid-cols-3 gap-2.5 max-w-[240px] mx-auto">
            <template x-for="n in [1, 2, 3, 4, 5, 6, 7, 8, 9]">
                <button type="button" @click="appendPin(n.toString())"
                    class="h-12 rounded-2xl bg-gray-50 hover:bg-indigo-50 hover:text-indigo-600 text-gray-800 text-lg font-bold transition-colors cursor-pointer border border-gray-200 shadow-xs flex items-center justify-center">
                    <span x-text="n"></span>
                </button>
            </template>
            <button type="button" @click="clearPin()"
                class="h-12 rounded-2xl bg-gray-100 hover:bg-gray-200 text-gray-600 text-xs font-bold transition-colors cursor-pointer border border-gray-200 shadow-xs flex items-center justify-center">
                CLEAR
            </button>
            <button type="button" @click="appendPin('0')"
                class="h-12 rounded-2xl bg-gray-50 hover:bg-indigo-50 hover:text-indigo-600 text-gray-800 text-lg font-bold transition-colors cursor-pointer border border-gray-200 shadow-xs flex items-center justify-center">
                0
            </button>
            <button type="button" @click="deletePin()"
                class="h-12 rounded-2xl bg-gray-100 hover:bg-gray-200 text-gray-600 transition-colors cursor-pointer border border-gray-200 shadow-xs flex items-center justify-center">
                <span class="material-symbols-rounded text-[20px]">backspace</span>
            </button>
        </div>

        <!-- Actions -->
        <div class="grid grid-cols-2 gap-2 pt-2 border-t border-gray-100" x-show="pin.length === 6">
            <button type="button" @click="$dispatch('kiosk-clock-in', { pin }); openKiosk = false;"
                class="py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-xs rounded-xl transition-colors cursor-pointer shadow-xs">
                Clock In
            </button>
            <button type="button" @click="$dispatch('kiosk-clock-out', { pin }); openKiosk = false;"
                class="py-2.5 bg-rose-600 hover:bg-rose-700 text-white font-bold text-xs rounded-xl transition-colors cursor-pointer shadow-xs">
                Clock Out
            </button>
        </div>
    </div>
</div>
