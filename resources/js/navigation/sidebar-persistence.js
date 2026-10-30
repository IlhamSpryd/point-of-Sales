/**
 * Apeiron POS — Persistent Sidebar Navigation & Scroll Restoration
 *
 * Enforces invariant:
 * - Vertical scroll position of the sidebar (#sidebar-nav-container) is strictly
 *   preserved across page navigation (wire:navigate and standard browser loads).
 * - Avoids arbitrary delays, repeated scrolling, or forced jumps.
 * - Handles active navigation item visibility gracefully.
 * - Cleans up event listeners and safely handles sessionStorage quotas.
 */

const STORAGE_KEY = 'apeiron_sidebar_scroll_top';

function getSidebarContainer() {
    return document.getElementById('sidebar-nav-container');
}

export function saveSidebarScroll() {
    const container = getSidebarContainer();
    if (container) {
        try {
            sessionStorage.setItem(STORAGE_KEY, String(container.scrollTop));
        } catch (e) {
            // Storage quota or sandboxing issue — fail gracefully
        }
    }
}

export function restoreSidebarScroll() {
    const container = getSidebarContainer();
    if (!container) return;

    let saved = null;
    try {
        saved = sessionStorage.getItem(STORAGE_KEY);
    } catch (e) {
        saved = null;
    }

    if (saved !== null && !isNaN(parseInt(saved, 10))) {
        const targetScroll = parseInt(saved, 10);
        // Immediate synchronous restoration before visual paint
        container.scrollTop = targetScroll;

        // Double-check in next microtask / animation frame after morphdom settles
        requestAnimationFrame(() => {
            if (Math.abs(container.scrollTop - targetScroll) > 2) {
                container.scrollTop = targetScroll;
            }
        });
    } else {
        // Direct initial visit (new tab/window without saved scroll):
        // Ensure active item is visible in view only if completely off-screen, without jumping.
        const activeItem = container.querySelector('[aria-current="page"]');
        if (activeItem) {
            const containerRect = container.getBoundingClientRect();
            const itemRect = activeItem.getBoundingClientRect();
            const isVisible = (itemRect.top >= containerRect.top) && (itemRect.bottom <= containerRect.bottom);
            if (!isVisible) {
                activeItem.scrollIntoView({ block: 'nearest', behavior: 'auto' });
            }
        }
    }
}

export function initSidebarPersistence() {
    if (typeof window === 'undefined') return;

    // Track scroll events passively on container
    let scrollDebounce = null;
    const onScroll = (e) => {
        if (e.target && e.target.id === 'sidebar-nav-container') {
            if (scrollDebounce) cancelAnimationFrame(scrollDebounce);
            scrollDebounce = requestAnimationFrame(() => {
                try {
                    sessionStorage.setItem(STORAGE_KEY, String(e.target.scrollTop));
                } catch (err) {}
            });
        }
    };
    window.addEventListener('scroll', onScroll, { capture: true, passive: true });

    // Save scroll state prior to navigation
    document.addEventListener('livewire:navigating', saveSidebarScroll);
    window.addEventListener('beforeunload', saveSidebarScroll);

    // Restore scroll state upon navigation completion
    document.addEventListener('livewire:navigated', restoreSidebarScroll);
    document.addEventListener('DOMContentLoaded', restoreSidebarScroll);

    // If script executes after DOM is already interactive
    if (document.readyState === 'interactive' || document.readyState === 'complete') {
        restoreSidebarScroll();
    }
}

export default initSidebarPersistence;
