/**
 * Apeiron ERP Command Palette Alpine Component (ADR-NAV-008)
 *
 * Dedicated JavaScript component module replacing bulky inline HTML attribute x-data.
 * Ensures zero HTML parser attribute collisions or character escaping issues.
 */
function getRecentsStorageKey() {
    const metaTenant = document.querySelector('meta[name="tenant-id"]')?.getAttribute('content');
    const metaUser = document.querySelector('meta[name="user-id"]')?.getAttribute('content');
    const tenantId = metaTenant || window.__APEIRON_BOOT__?.tenantId || window.ApeironTenantId || 'global';
    const userId = metaUser || window.__APEIRON_BOOT__?.userId || window.ApeironUserId || 'guest';

    // SEC-05: Purge legacy global key to eliminate cross-tenant leakage on shared terminals
    try {
        if (localStorage.getItem('apeiron.nav.recents')) {
            localStorage.removeItem('apeiron.nav.recents');
        }
    } catch (_) {}

    return `apeiron.nav.recents.t${tenantId}.u${userId}`;
}

if (typeof window !== 'undefined' && !window.__apeironCommandPaletteLogoutBound) {
    window.__apeironCommandPaletteLogoutBound = true;
    const clearActiveRecents = () => {
        try {
            const key = getRecentsStorageKey();
            localStorage.removeItem(key);
            localStorage.removeItem('apeiron.nav.recents');
        } catch (_) {}
    };

    const handleLogoutSubmit = (e) => {
        if (e.target && e.target.action && e.target.action.includes('logout')) {
            clearActiveRecents();
        }
    };

    window.addEventListener('apeiron:logout', clearActiveRecents);
    window.addEventListener('submit', handleLogoutSubmit);
}

export default function commandPalette(initialItems = []) {
    return {
        open: false,
        search: '',
        active: 0,
        items: Array.isArray(initialItems) ? initialItems : [],
        recents: [],
        previousActiveElement: null,

        get filtered() {
            const q = this.search.trim().toLowerCase();
            if (! q) {
                // Empty query = browse mode. Recently visited destinations are
                // pinned above the full list and filtered against current
                // visibility so a stale recents cache can never expose a page
                // the user has since lost access to.
                const visible = new Set(this.items.map(i => i.value));
                const recents = this.recents
                    .map(id => this.items.find(i => i.value === id))
                    .filter(Boolean)
                    .filter(i => visible.has(i.value));
                const seen = new Set(recents.map(i => i.value));
                const rest = this.items.filter(i => ! seen.has(i.value));
                return [...recents.map(i => ({ ...i, group: 'Recent' })), ...rest];
            }
            return this.items
                .map(item => [item, this.score(item, q)])
                .filter(([, score]) => score !== Infinity)
                .sort((a, b) => a[1] - b[1])
                .map(([item]) => item);
        },

        loadRecents() {
            try {
                const key = getRecentsStorageKey();
                this.recents = JSON.parse(localStorage.getItem(key) || '[]');
            } catch (e) {
                this.recents = [];
            }
        },

        remember(item) {
            if (! item || ! item.value) return;
            const next = [item.value, ...this.recents.filter(id => id !== item.value)].slice(0, 5);
            this.recents = next;
            try {
                const key = getRecentsStorageKey();
                localStorage.setItem(key, JSON.stringify(next));
            } catch (e) {}
        },

        // Fuzzy rank: prefix beats substring beats a scattered subsequence
        score(item, q) {
            const title = (item.title || '').toLowerCase();
            const hay = (item.haystack || title).toLowerCase();
            if (title.startsWith(q)) return 0;
            const index = title.indexOf(q);
            if (index !== -1) return 1 + index / 100;
            // Alias / Indonesian-vocabulary match
            const hayIndex = hay.indexOf(q);
            if (hayIndex !== -1) return 1.5 + hayIndex / 100;
            let at = 0, gaps = 0, previous = -2;
            for (const char of q) {
                at = title.indexOf(char, at);
                if (at === -1) return Infinity;
                if (at !== previous + 1) gaps++;
                previous = at;
                at++;
            }
            return 3 + gaps + title.length / 100;
        },

        openPalette() {
            // Hindari konflik jika modal pembayaran/PIN kasir aktif
            if (document.querySelector('#livewire-pos-root [aria-modal="true"]')) {
                return;
            }
            if (document.activeElement && typeof document.activeElement.focus === 'function') {
                this.previousActiveElement = document.activeElement;
            }
            this.open = true;
            this.search = '';
            this.active = 0;
            this.$nextTick(() => {
                if (this.$refs.search) {
                    this.$refs.search.focus();
                }
            });
        },

        closePalette() {
            this.open = false;
            if (this.previousActiveElement && typeof this.previousActiveElement.focus === 'function') {
                const el = this.previousActiveElement;
                this.$nextTick(() => {
                    try {
                        el.focus();
                    } catch (e) {}
                });
            }
        },

        move(dir) {
            const count = this.filtered.length;
            if (! count) return;
            this.active = (this.active + dir + count) % count;
            this.$nextTick(() => {
                const el = this.$refs.list && this.$refs.list.querySelector('[data-active="true"]');
                if (el) {
                    el.scrollIntoView({ block: 'nearest' });
                }
            });
        },

        select(item) {
            if (! item) return;
            if (window.apeironPosCartDirty && !confirm('You have an active cart in POS. Leaving this page will cancel the current cart. Continue?')) {
                return;
            } else if (window.apeironPosCartDirty) {
                window.apeironPosCartDirty = false;
            }
            this.remember(item);
            this.$dispatch('command-select', { value: item.value, title: item.title, url: item.url });
            this.closePalette();
            if (item.url) {
                if (window.Livewire && typeof window.Livewire.navigate === 'function') {
                    window.Livewire.navigate(item.url);
                } else {
                    window.location.href = item.url;
                }
            }
        },

        showGroup(item, index) {
            if (! item.group || this.search.trim() !== '') return false;
            return index === 0 || (this.filtered[index - 1] || {}).group !== item.group;
        }
    };
}
