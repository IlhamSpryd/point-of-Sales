@props([
    'duration' => 4500,
])

@php
    $initialFlashes = [];
    if (session('toast') && is_array(session('toast'))) {
        $initialFlashes[] = session('toast');
    }
    foreach (['success' => 'success', 'error' => 'error', 'warning' => 'warning', 'info' => 'info', 'message' => 'success'] as $sessionKey => $toastType) {
        if (session()->has($sessionKey) && is_string(session($sessionKey)) && trim(session($sessionKey)) !== '') {
            $initialFlashes[] = [
                'message' => session($sessionKey),
                'type' => $toastType,
            ];
        }
    }
@endphp

<template x-teleport="body">
    <div
        role="region"
        aria-label="Notifikasi sistem"
        aria-live="polite"
        {{ $attributes->twMerge('pointer-events-none fixed right-0 top-0 z-[99999] flex flex-col items-end gap-2 p-4 w-full sm:max-w-sm') }}
        x-data="{
            toasts: [],
            toastsProgress: {},
            initialFlashes: @js($initialFlashes),
            closeInterval: {{ (int) $duration }},
            normalizeType(rawType) {
                if (rawType === 'danger' || rawType === 'destructive') return 'error';
                if (['success', 'error', 'warning', 'info'].includes(rawType)) return rawType;
                return 'success';
            },
            handleWindowToast(detail) {
                if (!detail) return;
                const payload = Array.isArray(detail) ? detail[0] : detail;
                if (typeof payload === 'string') {
                    this.addToast(payload, 'success');
                    return;
                }
                this.addToast(
                    payload.message || payload.title || '',
                    this.normalizeType(payload.type || payload.variant || 'success'),
                    payload.description || '',
                    payload.action || null
                );
            },
            addToast(message, type = 'success', description = '', action = null) {
                if (!message) return;
                const normalizedType = this.normalizeType(type);
                const id = Date.now() + Math.random();
                const toast = { id, type: normalizedType, message, description, action, startTime: null, rafId: null, pausedAt: null, totalPausedTime: 0 };
                this.toasts.unshift(toast);
                this.toastsProgress[id] = 0;
                const duration = this.closeInterval;
                const animate = (timestamp) => {
                    if (!toast.startTime) toast.startTime = timestamp;
                    if (toast.pausedAt !== null) {
                        toast.rafId = requestAnimationFrame(animate);
                        return;
                    }
                    const elapsed = timestamp - toast.startTime - toast.totalPausedTime;
                    this.toastsProgress[id] = Math.round(Math.min((elapsed / duration) * 100, 100));
                    if (this.toastsProgress[id] < 100) {
                        toast.rafId = requestAnimationFrame(animate);
                    } else {
                        this.removeToast(id);
                    }
                };
                toast.rafId = requestAnimationFrame(animate);
            },
            pauseToast(id) {
                const toast = this.toasts.find(t => t.id === id);
                if (toast && toast.pausedAt === null) {
                    toast.pausedAt = performance.now();
                }
            },
            resumeToast(id) {
                const toast = this.toasts.find(t => t.id === id);
                if (toast && toast.pausedAt !== null) {
                    toast.totalPausedTime += performance.now() - toast.pausedAt;
                    toast.pausedAt = null;
                }
            },
            removeToast(id) {
                const idx = this.toasts.findIndex(t => t.id === id);
                if (idx !== -1) {
                    const toast = this.toasts[idx];
                    if (toast.rafId) cancelAnimationFrame(toast.rafId);
                    this.toasts = this.toasts.filter(t => t.id !== id);
                    delete this.toastsProgress[id];
                }
            },
            types: {
                success: { icon: 'check_circle', colorClass: 'text-emerald-600 bg-emerald-50 border-emerald-200' },
                error:   { icon: 'error',        colorClass: 'text-rose-600 bg-rose-50 border-rose-200' },
                warning: { icon: 'warning',      colorClass: 'text-amber-700 bg-amber-50 border-amber-200' },
                info:    { icon: 'info',         colorClass: 'text-blue-600 bg-blue-50 border-blue-200' }
            }
        }"
        x-init="(() => {
            window.toast = (message, type, description, action) => addToast(message, type, description, action);
            if (Array.isArray(initialFlashes)) {
                initialFlashes.forEach(f => addToast(f.message, f.type, f.description || '', f.action || null));
            }
        })()"
        @pop-toast.window="handleWindowToast($event.detail)"
        @toast.window="handleWindowToast($event.detail)"
        x-show="toasts.length > 0"
        x-cloak
    >
        <template x-for="toast in toasts" :key="toast.id">
            <div
                :id="'dd-toast-' + toast.id"
                @mouseenter="pauseToast(toast.id)"
                @mouseleave="resumeToast(toast.id)"
                class="pointer-events-auto relative flex w-full items-start gap-3 overflow-hidden rounded-xl border border-border bg-card p-3.5 text-xs text-card-foreground shadow-lg transition-all duration-200"
            >
                <span
                    class="inline-flex size-7 shrink-0 items-center justify-center rounded-lg border"
                    :class="types[toast.type] ? types[toast.type].colorClass : types.info.colorClass"
                >
                    <span class="material-symbols-rounded text-base leading-none" aria-hidden="true" x-text="types[toast.type] ? types[toast.type].icon : 'info'"></span>
                </span>

                <div class="flex-1 min-w-0 pr-5">
                    <p class="font-semibold text-foreground leading-snug" x-text="toast.message"></p>
                    <p x-show="toast.description" class="mt-0.5 text-muted-foreground leading-relaxed" x-text="toast.description"></p>
                    <template x-if="toast.action && toast.action.href">
                        <a :href="toast.action.href" class="mt-2 inline-flex items-center text-xs font-semibold text-foreground underline" x-text="toast.action.label || 'Lihat detail'"></a>
                    </template>
                </div>

                <button
                    type="button"
                    @click="removeToast(toast.id)"
                    aria-label="Tutup notifikasi"
                    class="inline-flex size-6 items-center justify-center rounded-md text-foreground/45 hover:bg-secondary hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring cursor-pointer"
                >
                    <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path d="M18 6 6 18M6 6l12 12" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                </button>

                <div
                    class="absolute bottom-0 left-0 h-0.5 bg-foreground/20 transition-all duration-150"
                    :style="`width: ${100 - (toastsProgress[toast.id] || 0)}%;`"
                    aria-hidden="true"
                ></div>
            </div>
        </template>
    </div>
</template>
