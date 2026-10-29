import gsap from 'gsap';

/**
 * Offline-first, accessible Apeiron Dialog Bridge compatible with Swal.fire() & Swal.showLoading().
 * Replaces external cdn.jsdelivr.net/npm/sweetalert2@11 dependency so POS terminals
 * operate 100% offline on local store LANs while respecting Apeiron design tokens & WCAG 2.1 AA.
 */
let activeOverlay = null;
let activeCleanup = null;

function prefersReducedMotion() {
    return typeof window !== 'undefined'
        && window.matchMedia
        && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
}

function closeActiveDialog() {
    if (activeCleanup) {
        activeCleanup();
        activeCleanup = null;
    }
    if (activeOverlay && activeOverlay.parentNode) {
        activeOverlay.parentNode.removeChild(activeOverlay);
    }
    activeOverlay = null;
}

const Swal = {
    fire(options = {}) {
        if (typeof options === 'string') {
            options = { title: options, text: arguments[1] || '', icon: arguments[2] || 'info' };
        }

        closeActiveDialog();

        const {
            title = 'Konfirmasi',
            text = '',
            html = '',
            icon = 'warning',
            showCancelButton = false,
            confirmButtonText = 'OK',
            cancelButtonText = 'Batal',
            confirmButtonColor = '#202020',
             returnFocus = true,
            reverseButtons = false,
            didOpen = null,
        } = options;

        const previouslyFocused = document.activeElement;

        return new Promise((resolve) => {
            const overlay = document.createElement('div');
            overlay.className = 'fixed inset-0 z-[100] flex items-center justify-center p-4 bg-black/40 backdrop-blur-[2px]';
            overlay.setAttribute('role', 'alertdialog');
            overlay.setAttribute('aria-modal', 'true');
            overlay.setAttribute('aria-labelledby', 'apeiron-swal-title');
            if (text) {
                overlay.setAttribute('aria-describedby', 'apeiron-swal-desc');
            }

            const panel = document.createElement('div');
            panel.className = 'w-full max-w-md rounded-2xl bg-white border border-[#E9E9E7] shadow-xl p-6 text-[#202020] focus:outline-none';
            panel.tabIndex = -1;

            const isDestructive = confirmButtonColor.toLowerCase() === '#be123c'
                || confirmButtonColor.toLowerCase() === '#e11d48'
                || icon === 'warning'
                || icon === 'error';

            const iconBadgeClass = isDestructive
                ? 'bg-[#FFF1F2] text-[#E11D48] border-[#FECDD3]'
                : 'bg-[#F7F7F5] text-[#202020] border-[#E9E9E7]';

            const iconSymbol = icon === 'error'
                ? 'error'
                : (icon === 'success' ? 'check_circle' : (icon === 'warning' ? 'warning' : 'info'));

            const confirmBtnClass = isDestructive
                ? 'inline-flex items-center justify-center h-10 px-4 rounded-lg text-xs font-semibold text-white bg-[#E11D48] hover:bg-[#BE123C] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#E11D48] transition-colors cursor-pointer'
                : 'inline-flex items-center justify-center h-10 px-4 rounded-lg text-xs font-semibold text-white bg-[#202020] hover:bg-[#141413] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#202020] transition-colors cursor-pointer';

            const cancelBtnClass = 'inline-flex items-center justify-center h-10 px-4 rounded-lg text-xs font-semibold text-[#202020] bg-white border border-[#E9E9E7] hover:bg-[#F7F7F5] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#202020] transition-colors cursor-pointer';

            panel.innerHTML = `
                <div class="flex items-start gap-4">
                    <div class="w-10 h-10 rounded-xl border ${iconBadgeClass} flex items-center justify-center shrink-0">
                        <span class="material-symbols-rounded text-xl" aria-hidden="true">${iconSymbol}</span>
                    </div>
                    <div class="flex-1 min-w-0">
                        <h3 id="apeiron-swal-title" class="text-base font-bold text-[#202020] tracking-tight"></h3>
                        <div id="apeiron-swal-desc" class="mt-1.5 text-xs text-[#6B6A66] leading-relaxed"></div>
                    </div>
                </div>
                <div data-swal-actions class="mt-6 flex items-center justify-end gap-2.5"></div>
            `;

            const titleEl = panel.querySelector('#apeiron-swal-title');
            titleEl.textContent = title;

            const descEl = panel.querySelector('#apeiron-swal-desc');
            if (html) {
                descEl.innerHTML = html;
            } else {
                descEl.textContent = text;
            }

            const actionsEl = panel.querySelector('[data-swal-actions]');
            const confirmBtn = document.createElement('button');
            confirmBtn.type = 'button';
            confirmBtn.className = confirmBtnClass;
            confirmBtn.setAttribute('data-swal-confirm', 'true');
            confirmBtn.textContent = confirmButtonText;

            const cancelBtn = document.createElement('button');
            cancelBtn.type = 'button';
            cancelBtn.className = cancelBtnClass;
            cancelBtn.setAttribute('data-swal-cancel', 'true');
            cancelBtn.textContent = cancelButtonText;

            if (showCancelButton) {
                if (reverseButtons) {
                    actionsEl.appendChild(cancelBtn);
                    actionsEl.appendChild(confirmBtn);
                } else {
                    actionsEl.appendChild(confirmBtn);
                    actionsEl.appendChild(cancelBtn);
                }
            } else {
                actionsEl.appendChild(confirmBtn);
            }

            overlay.appendChild(panel);
            document.body.appendChild(overlay);
            activeOverlay = overlay;

            const finish = (result) => {
                const restoreFocus = () => {
                    if (returnFocus && !result.isConfirmed && previouslyFocused && typeof previouslyFocused.focus === 'function') {
                        previouslyFocused.focus();
                    }
                };

                if (!prefersReducedMotion()) {
                    gsap.to(panel, {
                        opacity: 0,
                        scale: 0.96,
                        y: 6,
                        duration: 0.14,
                        ease: 'power2.in',
                        overwrite: 'auto',
                        onComplete: () => {
                            closeActiveDialog();
                            restoreFocus();
                            resolve(result);
                        },
                    });
                } else {
                    closeActiveDialog();
                    restoreFocus();
                    resolve(result);
                }
            };

            const onKeyDown = (e) => {
                if (e.key === 'Escape') {
                    e.preventDefault();
                    finish({ isConfirmed: false, isDismissed: true });
                    return;
                }
                if (e.key === 'Tab') {
                    const focusables = Array.from(panel.querySelectorAll('button:not([disabled])'));
                    if (focusables.length === 0) return;
                    const first = focusables[0];
                    const last = focusables[focusables.length - 1];
                    if (e.shiftKey && document.activeElement === first) {
                        e.preventDefault();
                        last.focus();
                    } else if (!e.shiftKey && document.activeElement === last) {
                        e.preventDefault();
                        first.focus();
                    }
                }
            };

            document.addEventListener('keydown', onKeyDown);
            activeCleanup = () => {
                document.removeEventListener('keydown', onKeyDown);
            };

            confirmBtn.addEventListener('click', () => finish({ isConfirmed: true, isDismissed: false }));
            cancelBtn.addEventListener('click', () => finish({ isConfirmed: false, isDismissed: true }));
            overlay.addEventListener('click', (e) => {
                if (e.target === overlay) {
                    finish({ isConfirmed: false, isDismissed: true });
                }
            });

            if (!prefersReducedMotion()) {
                gsap.fromTo(
                    panel,
                    { opacity: 0, scale: 0.96, y: 8 },
                    { opacity: 1, scale: 1, y: 0, duration: 0.2, ease: 'power3.out', overwrite: 'auto' }
                );
            }

            if (typeof didOpen === 'function') {
                didOpen(panel);
            }

            requestAnimationFrame(() => {
                confirmBtn.focus();
            });
        });
    },

    showLoading() {
        if (!activeOverlay) return;
        const actionsEl = activeOverlay.querySelector('[data-swal-actions]');
        if (actionsEl) {
            actionsEl.innerHTML = `
                <div class="inline-flex items-center gap-2 text-xs font-medium text-[#6B6A66]" role="status" aria-live="polite">
                    <span class="spinner !border-[#202020]/20 !border-t-[#202020]"></span>
                    <span>Memproses...</span>
                </div>
            `;
        }
    },

    close() {
        closeActiveDialog();
    },
};

export default Swal;
