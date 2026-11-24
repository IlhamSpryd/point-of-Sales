# 08 — Theme Switching Architecture

> **Recommendation:** counter-based `.dark` toggle on `<html>`, persisted in `localStorage`,
> with `system` as the default, initialized by a **synchronous inline script** before first
> paint. No dependency, no Laravel-side state, no flash.

---

## 1. Modes and Semantics

| Mode | Meaning | `class="dark"` on `<html>` |
|---|---|---|
| `light` | Always light | never |
| `dark` | Always dark | always |
| `system` | Follow OS | when `prefers-color-scheme: dark` matches |

**Default on first visit: `system`.** Rationale: POS terminals are often shared; an operator who
sets dark on one device should not force it on a supervisor's device. `system` respects the
machine owner's OS preference, which in practice is the right answer for shared hardware.

Storage key: **`apeiron-theme`** with values `light | dark | system`.
Do **not** reuse `sidebarExpanded`'s key or storage object — that key already exists in
`sidebar-persistence.js`.

---

## 2. Why Not Server-Side Theme

A `UserThemePreference` column would:
- require a migration, a model change, and a Livewire round-trip before first paint;
- still flash on first HTML byte because the server must decide before the DOM exists;
- break POS offline mode (`docs` note that POS runs offline-first).

Local storage + pre-paint script is strictly better for this application. If a per-user
preference is ever required, it becomes a **seed value** written into `localStorage` on login —
the runtime path stays identical.

---

## 3. Flash Prevention (the only correct pattern)

The theme class must be applied **before the browser paints the first frame**. That means an
inline script in `<head>`, after the `<html>` element exists and before any stylesheet that
uses selectors is applied.

```blade
{{-- resources/views/layouts/app.blade.php — inside <head>, FIRST child after <meta charset> --}}
<script>
(function () {
    try {
        var stored = localStorage.getItem('apeiron-theme') || 'system';
        var prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
        var dark = stored === 'dark' || (stored === 'system' && prefersDark);
        document.documentElement.classList.toggle('dark', dark);
        document.documentElement.style.colorScheme = dark ? 'dark' : 'light';
        document.documentElement.dataset.themeMode = stored;
    } catch (e) {
        /* Private mode / storage blocked: fall back to light. */
    }
})();
</script>
```

Properties that make this work:

| Property | Reason |
|---|---|
| **Inline**, not `app.js` | `app.js` is a deferred module; it runs after first paint → visible flash |
| Before `@vite(...)` | Ensures the class is set before the CSS applies |
| Wrapped in `try/catch` | `localStorage` throws in Safari private mode; an uncaught throw would abort head parsing |
| Sets `colorScheme` | Keeps native scrollbars/form controls in sync (otherwise a dark page shows a white scrollbar) |
| Sets `dataset.themeMode` | Lets CSS/Alpine read the *mode* (`light`/`dark`/`system`) vs. the *resolved* theme |

`color-scheme` must also be declared in CSS (`:root { color-scheme: light }` / `.dark { color-scheme: dark }`),
which is already present in `tokens.css`. The inline script's `style.colorScheme` covers the
pre-CSS window; the CSS covers everything after.

---

## 4. Runtime Toggle

Minimal Alpine component, mounted in the sidebar footer and available globally for the
command palette / user dropdown:

```js
// resources/js/theme/theme-controller.js  (new file — see §7)
export function themeController() {
    return {
        mode: document.documentElement.dataset.themeMode || 'system',
        get resolved() {
            return document.documentElement.classList.contains('dark') ? 'dark' : 'light';
        },
        set(next) {
            this.mode = next;
            try { localStorage.setItem('apeiron-theme', next); } catch (e) {}
            this.apply();
        },
        cycle() {
            this.set({ light: 'dark', dark: 'system', system: 'light' }[this.mode] ?? 'system');
        },
        apply() {
            const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
            const dark = this.mode === 'dark' || (this.mode === 'system' && prefersDark);
            document.documentElement.classList.toggle('dark', dark);
            document.documentElement.style.colorScheme = dark ? 'dark' : 'light';
            document.documentElement.dataset.themeMode = this.mode;
        },
        init() {
            this.apply();
            // React to OS changes only while in system mode
            window.matchMedia('(prefers-color-scheme: dark)')
                .addEventListener('change', () => { if (this.mode === 'system') this.apply(); });
            // Sync across tabs
            window.addEventListener('storage', (e) => {
                if (e.key === 'apeiron-theme') { this.mode = e.newValue || 'system'; this.apply(); }
            });
        },
    };
}
```

Registration follows the existing pattern in `resources/js/app.js`
(`document.addEventListener('alpine:init', …)` — Livewire 4 owns the Alpine instance).

### 4.1 Alpine `x-cloak` interaction

`[x-cloak] { display: none !important; }` is already global. The toggle button must not be
`x-cloak`-ed on the icon only, or the sidebar footer will jump on hydration. Render a neutral
icon placeholder and swap the glyph — the icon swap is not theme-related and shows no flash.

### 4.2 Icons and labels

Use **neutral** glyphs only (`light_mode`, `dark_mode`, `brightness_auto`) rendered in
`text-shell-foreground`. Never tint the theme toggle — it is a control, not a status.

---

## 5. Livewire `wire:navigate` Compatibility

Livewire's SPA navigation swaps `<body>` content and merges `<head>`. Consequences:

| Risk | Behavior | Mitigation |
|---|---|---|
| `class="dark"` lost on navigation | **No** — the `<html>` element is preserved across `wire:navigate` | none needed |
| Inline theme script re-executed | Livewire does not re-run head scripts already present | none needed |
| `data-theme="kds-dark"` on a KDS page leaking back | KDS lives on `<html>` attributes in its own layout | KDS layout must set/remove the attribute on init (`06`/KDS unify later) |
| Alpine component re-initialized | `init()` re-runs, `apply()` is idempotent | by design |
| Theme reset after login redirect (full reload) | Full reload re-runs the inline script | correct by design |

**Verified assumption:** no `classList.add('dark')` exists anywhere today, so there is no
competing writer to reconcile.

---

## 6. Interaction With the KDS Theme

`[data-theme="kds-dark"]` is a **separate axis** and must stay separate:

| Screen | Mechanism |
|---|---|
| Admin / ERP | `.dark` class on `<html>` |
| POS | `.dark` class on `<html>` (inherits) |
| KDS | `data-theme="kds-dark"` attribute on the KDS root, plus its own `--color-apeiron-*` overrides |
| Customer self-order | `.dark` class on `<html>` |

Rule: a KDS screen **forces** its own visual treatment regardless of user preference — a kitchen
display is a wall-mounted TV, and a cashier's dark-mode preference should not tint it.
The KDS layout should therefore set `data-theme="kds-dark"` unconditionally and **not** apply
`.dark`. They are orthogonal; do not merge them into one mechanism, despite the earlier audit
suggesting it (`07-responsive-and-theme-audit.md` TH-02). The reason to keep them separate is
that KDS needs *different type scaling* (18px/24px at 1920/2560px), which is a typographic
decision that cannot be expressed by a color-class toggle.

> This is a **documented divergence** from the prior audit's recommendation (README §12, C-7).

---

## 7. Where the Code Goes

| File | Status | Content |
|---|---|---|
| `resources/views/layouts/app.blade.php` | modify (P5) | inline no-flash script in `<head>` |
| `resources/views/layouts/guest.blade.php` | modify (P5) | same inline script (auth pages must honor the pref) |
| `resources/views/layouts/customer.blade.php` | modify (P5) | same inline script |
| `resources/js/theme/theme-controller.js` | **new** | Alpine factory (§4) |
| `resources/js/app.js` | modify (P5) | register the factory on `alpine:init` |
| Sidebar footer / user dropdown | modify (P5) | toggle button |

Total footprint: **one new JS file, four small edits.** No dependency, no build change.

---

## 8. Persistence Strategy Comparison

| Strategy | Flash-free | Works offline | Shared-device safe | Complexity |
|---|---|---|---|---|
| Server column (DB) | ❌ (needs round-trip or inline echo) | ❌ | ✅ | High |
| Cookie (server-set) | ✅ (if read server-side) | ✅ | ✅ | Medium — cookie must be sent on every request |
| **`localStorage` + inline script** | ✅ | ✅ | ✅ (per-device) | **Low** |
| `prefers-color-scheme` only | ✅ | ✅ | ✅ | Lowest, but user cannot override |

**Chosen:** `localStorage`, because POS terminals run offline and a cookie would add
unnecessary request weight to Livewire polling.

---

## 9. Test Strategy

| Test | Method |
|---|---|
| No flash on hard reload in dark mode | Record first 200 ms; no white frame (`10` AC-DK1) |
| Toggle persists across hard reload | Manual + Playwright |
| Toggle persists across `wire:navigate` | Navigate 5 routes; class retained |
| `system` follows OS change live | Toggle OS appearance while page open |
| Cross-tab sync | Two tabs, toggle in one, observe the other |
| Private-mode fallback | Safari private window: no console error, defaults to light |
| KDS unaffected by user preference | Open KDS while dark is set → KDS unchanged |
| Auth pages honor preference | Sign out → login page is dark |

---

## 10. Activation Gate

Dark mode may **only** be enabled after these are true (all are CR gates from `04` §5):

- [ ] CR-1: zero palette utilities in views
- [ ] CR-2: zero arbitrary color brackets
- [ ] CR-3: zero `dark:` utilities
- [ ] CR-4: zero `bg-white` / `bg-black`
- [ ] CR-5: zero gradients
- [ ] CSS-level hex removed from `app.css` (`.chip*`, `.field-input*`, `.delta-badge`, `.restock-bar i`, `.dot-*`, `option`, `.table-scroll-shadow`, `.spinner`, `.sheet-overlay`, `.editorial-surface`)
- [ ] `scratch/contrast_check.py` exits 0

Enabling sooner produces the half-dark interface described in `01` §5.

---

## 11. Documented Divergence

| ID | Prior doc | This plan |
|---|---|---|
| C-7 | `07-responsive-and-theme-audit.md` TH-02 recommends unifying KDS `data-theme` and global `.dark` into one mechanism | Keep them orthogonal: `.dark` for color preference, `data-theme="kds-dark"` for the KDS typographic/legibility profile. Merging would force KDS type scaling through a color toggle. |
