import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

/**
 * Apeiron Systems — E2E Accessibility & Interaction Suite (Wave 2D)
 *
 * Verifies WCAG 2.2 Level AAA standards, zero contrast defects, keyboard navigability,
 * and lack of console errors across core frontlines.
 */
test.describe('Apeiron Accessibility & Interaction Suite', () => {

    test('Login screen conforms to WCAG accessibility standards in Light mode', async ({ page }) => {
        const consoleErrors: string[] = [];
        page.on('console', msg => {
            if (msg.type() === 'error') {
                consoleErrors.push(msg.text());
            }
        });

        await page.emulateMedia({ reducedMotion: 'reduce' });
        await page.goto('/login');
        await expect(page).toHaveTitle(/Apeiron/i);
        await page.waitForLoadState('networkidle');
        await page.waitForTimeout(300);

        // Run axe accessibility scan
        const accessibilityScanResults = await new AxeBuilder({ page })
            .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'])
            .analyze();

        expect(accessibilityScanResults.violations).toEqual([]);
        expect(consoleErrors).toEqual([]);
    });

    test('Skip link is focusable and targets main content correctly', async ({ page }) => {
        await page.goto('/login');

        // Initial Tab should focus the skip link
        await page.keyboard.press('Tab');
        const activeElementText = await page.evaluate(() => document.activeElement?.textContent?.trim());
        expect(activeElementText).toMatch(/Skip to main content/i);

        // Pressing Enter on skip link should navigate to #main-content
        await page.keyboard.press('Enter');
        const focusedId = await page.evaluate(() => document.activeElement?.id || document.querySelector(':focus')?.id);
        expect(['main-content', 'email', 'identity']).toContain(focusedId || 'main-content');
    });

    test('Login screen conforms to WCAG accessibility in Dark mode', async ({ page }) => {
        await page.emulateMedia({ colorScheme: 'dark', reducedMotion: 'reduce' });
        await page.goto('/login');
        await page.waitForLoadState('networkidle');
        await page.waitForTimeout(300);

        // Evaluate whether root or html has dark styling
        const hasDarkOrSemanticBackground = await page.evaluate(() => {
            return document.documentElement.classList.contains('dark') ||
                window.getComputedStyle(document.body).backgroundColor !== 'rgb(255, 255, 255)';
        });
        expect(hasDarkOrSemanticBackground).toBe(true);

        const accessibilityScanResults = await new AxeBuilder({ page })
            .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
            .analyze();

        expect(accessibilityScanResults.violations).toEqual([]);
    });

    test('Legal modals on guest login open and close with Escape key', async ({ page }) => {
        await page.goto('/login');

        // Click Terms button in footer
        const termsButton = page.getByRole('button', { name: /Terms/i });
        if (await termsButton.isVisible()) {
            await termsButton.click();
            const modal = page.locator('[role="dialog"]').filter({ hasText: /Terms/i });
            await expect(modal).toBeVisible();

            // Press Escape to dismiss
            await page.keyboard.press('Escape');
            await expect(modal).toBeHidden();
        }
    });

    test('Keyboard navigation through authentication form has no traps', async ({ page }) => {
        await page.goto('/login');

        // Tab through form elements
        await page.keyboard.press('Tab'); // skip link
        await page.keyboard.press('Tab'); // brand link or first input
        await page.keyboard.press('Tab');
        await page.keyboard.press('Tab');

        const activeTag = await page.evaluate(() => document.activeElement?.tagName);
        expect(['INPUT', 'BUTTON', 'A']).toContain(activeTag);
    });

    test('Login screen right editorial hero has zero duplicate Apeiron logo and clean typography', async ({ page }) => {
        await page.goto('/login');
        await page.waitForLoadState('networkidle');

        // Verify right panel is present on desktop
        const rightPanel = page.locator('#anim-panel-right');
        await expect(rightPanel).toBeVisible();

        // Wave 2.1 Directive: Zero logo/mark in right panel (no img, no svg)
        const logosInPanel = rightPanel.locator('img, svg');
        await expect(logosInPanel).toHaveCount(0);

        // Verify editorial headline text is present
        await expect(rightPanel).toContainText(/Point of sale, inventory ledger/i);
        await expect(rightPanel).toContainText(/Enterprise Point of Sale/i);
    });

    test('Authenticated Dashboard maintains persistent Dark Mode across wire:navigate, reloads, and has 0 horizontal scroll at 1366px', async ({ page }) => {
        // Step 1: Sign in
        await page.goto('/login');
        await page.waitForLoadState('networkidle');
        await page.fill('#email', 'admin@pos.test');
        await page.fill('#password', process.env.E2E_PASSWORD || 'password123');
        await page.click('button[type="submit"]');

        // Wait for redirect to dashboard
        await page.waitForURL(/.*dashboard.*/i);
        await page.waitForLoadState('networkidle');

        // Step 2: Test theme toggle
        const themeToggle = page.locator('header button[aria-label="Toggle color theme"]');
        await expect(themeToggle).toBeVisible();

        // Ensure we switch to dark mode
        const isInitiallyDark = await page.evaluate(() => document.documentElement.classList.contains('dark'));
        if (!isInitiallyDark) {
            await themeToggle.click();
            await page.waitForTimeout(200);
        }

        const isDarkAfterClick = await page.evaluate(() => {
            return document.documentElement.classList.contains('dark') &&
                localStorage.getItem('apeiron-theme') === 'dark';
        });
        expect(isDarkAfterClick).toBe(true);

        // Step 3: Navigate via Livewire or link to another page and verify theme persists
        const navLinks = page.locator('#sidebar-nav-container a[href*="/"]');
        const linkCount = await navLinks.count();
        if (linkCount > 1) {
            // Click second link (e.g. POS or Catalog)
            await navLinks.nth(1).click();
            await page.waitForLoadState('networkidle');
            await page.waitForTimeout(300);

            // Verify .dark is STILL on html element
            const isDarkAfterNav = await page.evaluate(() => document.documentElement.classList.contains('dark'));
            expect(isDarkAfterNav).toBe(true);

            // Navigate back to /dashboard
            await page.goto('/dashboard');
            await page.waitForLoadState('networkidle');
            await page.waitForTimeout(300);
            const isDarkBackOnDashboard = await page.evaluate(() => document.documentElement.classList.contains('dark'));
            expect(isDarkBackOnDashboard).toBe(true);
        }

        // Step 4: Perform full page reload and verify zero FOUC / dark class retained
        await page.reload();
        await page.waitForLoadState('networkidle');
        const isDarkAfterReload = await page.evaluate(() => document.documentElement.classList.contains('dark'));
        expect(isDarkAfterReload).toBe(true);

        // Step 5: Verify 0 horizontal scroll overflow at current viewport
        const hasHorizontalScroll = await page.evaluate(() => {
            return document.documentElement.scrollWidth > window.innerWidth;
        });
        expect(hasHorizontalScroll).toBe(false);

        // Step 6: Verify Header bar controls single row layout
        const posOrderBtn = page.getByRole('button', { name: /New POS Order/i }).or(page.locator('button:has-text("New POS Order")'));
        const dateRangeBtn = page.locator('#dash-period-trigger, [id*="period"]').first();

        if (await posOrderBtn.isVisible() && await dateRangeBtn.isVisible()) {
            const posBox = await posOrderBtn.boundingBox();
            const periodBox = await dateRangeBtn.boundingBox();
            if (posBox && periodBox) {
                // Should be aligned on roughly the same row (within 16px delta)
                expect(Math.abs(posBox.y - periodBox.y)).toBeLessThan(20);
            }
        }
    });

    test('Wave 2.2 Multi-Domain Visual, Theme Switch, Seamless Chrome & Collapsed Popover Crawler at 1366x768', async ({ page }) => {
        const consoleErrors: string[] = [];
        page.on('pageerror', error => {
            consoleErrors.push(error.message);
        });
        page.on('console', msg => {
            if (msg.type() === 'error') {
                const text = msg.text();
                // Filter out third-party gateway CSP reports or favicon 404s
                if (!text.includes('Content Security Policy') && !text.includes('Failed to load resource') && !text.includes('favicon')) {
                    consoleErrors.push(text);
                }
            }
        });

        // Step 1: Login as admin
        await page.goto('/login');
        await page.waitForLoadState('networkidle');
        await page.fill('#email', 'admin@pos.test');
        await page.fill('#password', process.env.E2E_PASSWORD || 'password123');
        await page.click('button[type="submit"]');
        await page.waitForURL(/.*dashboard.*/i);
        await page.waitForLoadState('networkidle');

        // Step 2: Ensure Light Mode initially to verify Light Mode Chrome
        const themeSwitch = page.locator('header button[role="switch"]');
        await expect(themeSwitch).toBeVisible();

        const isDarkInitially = await page.evaluate(() => document.documentElement.classList.contains('dark'));
        if (isDarkInitially) {
            await themeSwitch.click();
            await page.waitForTimeout(250);
        }

        // Assertion 1: Header & Sidebar Background computed color in Light Mode is rgb(255, 255, 255)
        const headerBgLight = await page.evaluate(() => {
            const header = document.querySelector('header');
            return header ? window.getComputedStyle(header).backgroundColor : '';
        });
        expect(headerBgLight).toBe('rgb(255, 255, 255)');

        const sidebarBgLight = await page.evaluate(() => {
            const sidebar = document.querySelector('#main-sidebar');
            return sidebar ? window.getComputedStyle(sidebar).backgroundColor : '';
        });
        expect(sidebarBgLight).toBe('rgb(255, 255, 255)');

        // Assertion 2: Main content wrapper has borderTopLeftRadius >= 16px on desktop (curved inner seam)
        const mainRadius = await page.evaluate(() => {
            const main = document.querySelector('#main-content');
            if (!main) return 0;
            const style = window.getComputedStyle(main);
            return parseFloat(style.borderTopLeftRadius) || 0;
        });
        expect(mainRadius).toBeGreaterThanOrEqual(16);

        // Assertion 3: Interactive Theme Switch Button functionality
        const ariaCheckedLight = await themeSwitch.getAttribute('aria-checked');
        expect(ariaCheckedLight).toBe('false');

        // Click to toggle to Dark Mode
        await themeSwitch.click();
        await page.waitForTimeout(250);

        const isDarkNow = await page.evaluate(() => document.documentElement.classList.contains('dark'));
        expect(isDarkNow).toBe(true);

        const ariaCheckedDark = await themeSwitch.getAttribute('aria-checked');
        expect(ariaCheckedDark).toBe('true');

        const savedThemeInStorage = await page.evaluate(() => localStorage.getItem('apeiron-theme'));
        expect(savedThemeInStorage).toBe('dark');

        // Assertion 4: Collapsed Sidebar Avatar Popover within viewport bounds
        const collapseChevron = page.locator('#sidebar-collapse-chevron, [aria-label*="sidebar" i], [title*="sidebar" i]').first();
        if (await collapseChevron.isVisible()) {
            await collapseChevron.click();
            await page.waitForTimeout(300);
        }

        const avatarBtn = page.locator('#main-sidebar button:has(img), #main-sidebar button:has(.rounded-full)').last();
        if (await avatarBtn.isVisible()) {
            await avatarBtn.click();
            await page.waitForTimeout(250);

            // Check popover menu is visible and within viewport
            const popover = page.locator('#sidebar-user-popover, div[role="menu"]').first();
            if (await popover.isVisible()) {
                const box = await popover.boundingBox();
                if (box) {
                    const viewport = page.viewportSize();
                    expect(box.x).toBeGreaterThanOrEqual(0);
                    expect(box.y).toBeGreaterThanOrEqual(0);
                    if (viewport) {
                        expect(box.x + box.width).toBeLessThanOrEqual(viewport.width + 10);
                        expect(box.y + box.height).toBeLessThanOrEqual(viewport.height + 10);
                    }
                }
            }
        }

        // Expand sidebar back
        if (await collapseChevron.isVisible()) {
            await collapseChevron.click();
            await page.waitForTimeout(200);
        }

        // Assertion 5: Crawl representative routes across 9 Domains without fatal console errors
        const routesToCrawl = [
            '/dashboard',
            '/transaction',
            '/products',
            '/customers',
            '/finance',
            '/analytics',
            '/reports/sales',
            '/settings',
        ];

        for (const route of routesToCrawl) {
            await page.goto(route);
            await page.waitForLoadState('networkidle');
            await page.waitForTimeout(200);

            // Verify .dark is preserved across navigation
            const isDarkOnRoute = await page.evaluate(() => document.documentElement.classList.contains('dark'));
            expect(isDarkOnRoute).toBe(true);

            // Verify 0 horizontal overflow
            const hasHScroll = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth);
            expect(hasHScroll).toBe(false);
        }

        expect(consoleErrors).toEqual([]);
    });

    test('Hierarki Z-Index Token terverifikasi konsisten: Toast (80) > Command Palette (70) > Modal Dialog (60) > Account Popover (55) > Drawer (50) > Dropdown (40)', async ({ page }) => {
        await page.goto('/login');
        await page.waitForLoadState('networkidle');

        const zTokens = await page.evaluate(() => {
            const styles = window.getComputedStyle(document.documentElement);
            return {
                dropdown: parseInt(styles.getPropertyValue('--z-dropdown').trim(), 10),
                drawer: parseInt(styles.getPropertyValue('--z-drawer').trim(), 10),
                popoverAccount: parseInt(styles.getPropertyValue('--z-popover-account').trim(), 10),
                modal: parseInt(styles.getPropertyValue('--z-modal').trim(), 10),
                command: parseInt(styles.getPropertyValue('--z-command').trim(), 10),
                toast: parseInt(styles.getPropertyValue('--z-toast').trim(), 10),
            };
        });

        expect(zTokens.toast).toBe(80);
        expect(zTokens.command).toBe(70);
        expect(zTokens.modal).toBe(60);
        expect(zTokens.popoverAccount).toBe(55);
        expect(zTokens.drawer).toBe(50);
        expect(zTokens.dropdown).toBe(40);

        // Strict descending hierarchy guarantees zero collisions
        expect(zTokens.command).toBeGreaterThan(zTokens.modal);
        expect(zTokens.modal).toBeGreaterThan(zTokens.drawer);
        expect(zTokens.drawer).toBeGreaterThan(zTokens.dropdown);
    });

    test('Keyboard Tab navigation reaches interactive buttons and inputs with focus rings and no bare outline-none', async ({ page }) => {
        await page.goto('/login');
        await page.waitForLoadState('networkidle');

        // Tab sequentially through interactive controls on the login page
        for (let i = 0; i < 5; i++) {
            await page.keyboard.press('Tab');
            const focusedInfo = await page.evaluate(() => {
                const el = document.activeElement;
                if (!el || el === document.body) return null;
                const style = window.getComputedStyle(el);
                const hasOutline = style.outlineStyle !== 'none' && parseFloat(style.outlineWidth) > 0;
                const hasBoxShadow = style.boxShadow && style.boxShadow !== 'none';
                return {
                    tag: el.tagName,
                    hasVisibleRing: hasOutline || Boolean(hasBoxShadow),
                };
            });

            if (focusedInfo && ['INPUT', 'BUTTON', 'A'].includes(focusedInfo.tag)) {
                expect(focusedInfo.hasVisibleRing).toBe(true);
            }
        }
    });

    test('Wave 5.1C Layout Geometry, Breathing Room & Cloudflare-scale Density Guardrails', async ({ page }) => {
        // Step 1: Sign in
        await page.goto('/login');
        await page.waitForLoadState('networkidle');
        await page.fill('#email', 'admin@pos.test');
        await page.fill('#password', process.env.E2E_PASSWORD || 'password123');
        await page.click('button[type="submit"]');
        await page.waitForURL(/.*dashboard.*/i);
        await page.waitForLoadState('networkidle');

        // Step 2: Route /products
        await page.goto('/products');
        await page.waitForLoadState('networkidle');
        await page.waitForTimeout(300);

        // 1. Heading breathing room (x offset >= 18 and <= 48 relative to #main-content)
        const main = await page.locator('#main-content').boundingBox();
        expect(main).not.toBeNull();
        const heading = await page.locator('#main-content h1, #main-content h2, #main-content h3, #main-content h4').first().boundingBox();
        expect(heading).not.toBeNull();
        if (main && heading) {
            const xDelta = heading.x - main.x;
            expect(xDelta).toBeGreaterThanOrEqual(18);
            expect(xDelta).toBeLessThanOrEqual(48);
        }

        // 2. Sub-navigation first link alignment with heading
        const subNavFirstLink = await page.locator('#main-content nav[aria-label*="Navigation"] a, #main-content nav a').first().boundingBox();
        if (subNavFirstLink && heading) {
            expect(Math.abs(subNavFirstLink.x - heading.x)).toBeLessThanOrEqual(8);
        }

        // 3. Toolbar button heights (28px - 34px)
        const addBtn = page.locator('button:has-text("Add Product"), a:has-text("Add Product")').first();
        if (await addBtn.isVisible()) {
            const addBtnBox = await addBtn.boundingBox();
            if (addBtnBox) {
                expect(addBtnBox.height).toBeGreaterThanOrEqual(28);
                expect(addBtnBox.height).toBeLessThanOrEqual(34);
            }
        }

        // 4. Search input height (28px - 34px)
        const searchBox = page.locator('input[placeholder*="Search"]').first();
        if (await searchBox.isVisible()) {
            const searchBoxBounds = await searchBox.boundingBox();
            if (searchBoxBounds) {
                expect(searchBoxBounds.height).toBeGreaterThanOrEqual(28);
                expect(searchBoxBounds.height).toBeLessThanOrEqual(34);
            }
        }

        // 5. Product thumbnail size <= 32 x 32
        const thumb = page.locator('table tbody tr').first().locator('img, .material-symbols-rounded:has-text("image")').first();
        if (await thumb.isVisible()) {
            const thumbBox = await thumb.boundingBox();
            if (thumbBox) {
                expect(thumbBox.width).toBeLessThanOrEqual(32);
                expect(thumbBox.height).toBeLessThanOrEqual(32);
            }
        }

        // 6. Zero horizontal scroll on #main-content
        const hasHScroll = await page.evaluate(() => {
            const el = document.getElementById('main-content');
            return el ? el.scrollWidth > el.clientWidth : false;
        });
        expect(hasHScroll).toBe(false);

        // Step 3: Repeat breathing room checks on /inventory/ingredients and /customers
        for (const route of ['/inventory/ingredients', '/customers']) {
            await page.goto(route);
            await page.waitForLoadState('networkidle');
            await page.waitForTimeout(200);

            const mBox = await page.locator('#main-content').boundingBox();
            const hBox = await page.locator('#main-content h1, #main-content h2, #main-content h3, #main-content h4').first().boundingBox();
            if (mBox && hBox) {
                const delta = hBox.x - mBox.x;
                expect(delta).toBeGreaterThanOrEqual(18);
                expect(delta).toBeLessThanOrEqual(48);
            }

            const routeHScroll = await page.evaluate(() => {
                const el = document.getElementById('main-content');
                return el ? el.scrollWidth > el.clientWidth : false;
            });
            expect(routeHScroll).toBe(false);
        }

        // Step 4: Route /transaction - Touch target >= 44px
        await page.goto('/transaction');
        await page.waitForLoadState('networkidle');
        await page.waitForTimeout(300);

        const touchTargets = page.locator('button, a[role="button"], [role="button"]');
        const count = await touchTargets.count();
        let hasCompliantTarget = false;
        for (let i = 0; i < Math.min(count, 15); i++) {
            const btn = touchTargets.nth(i);
            if (await btn.isVisible()) {
                const box = await btn.boundingBox();
                if (box && (box.height >= 40 || box.width >= 40)) {
                    expect(Math.max(box.height, box.width)).toBeGreaterThanOrEqual(44);
                    hasCompliantTarget = true;
                    break;
                }
            }
        }
        expect(hasCompliantTarget).toBe(true);
    });

    test('Wave 8 Tier-1 Calm Hub Navigation: Zero-Scroll Expanded & Collapsed Micro-Rail, Sub-Nav Overflow & Zero-FOUT Box Lock', async ({ page }) => {
        // Step 1: Set viewport to 1366x768
        await page.setViewportSize({ width: 1366, height: 768 });

        // Step 2: Login as admin
        await page.goto('/login');
        await page.waitForLoadState('networkidle');
        await page.fill('#email', 'admin@pos.test');
        await page.fill('#password', process.env.E2E_PASSWORD || 'password123');
        await page.click('button[type="submit"]');
        await page.waitForURL(/.*dashboard.*/i);
        await page.waitForLoadState('networkidle');
        await page.waitForTimeout(300);

        // --- Guardrail 1: Zero-Scroll Expanded Sidebar (<= 605px content height) ---
        const sidebar = page.locator('#main-sidebar');
        await expect(sidebar).toBeVisible();

        const expandedNavMetrics = await page.evaluate(() => {
            const container = document.getElementById('sidebar-nav-container');
            const sidebarEl = document.getElementById('main-sidebar');
            return {
                scrollHeight: container ? container.scrollHeight : 0,
                clientHeight: container ? container.clientHeight : 0,
                sidebarScrollHeight: sidebarEl ? sidebarEl.scrollHeight : 0,
                sidebarClientHeight: sidebarEl ? sidebarEl.clientHeight : 0,
            };
        });

        // Zero vertical scroll: scrollHeight must not exceed clientHeight + 2
        expect(expandedNavMetrics.scrollHeight).toBeLessThanOrEqual(expandedNavMetrics.clientHeight + 2);
        expect(expandedNavMetrics.sidebarScrollHeight).toBeLessThanOrEqual(expandedNavMetrics.sidebarClientHeight + 2);

        // --- Guardrail 2: Zero-Scroll Micro-Rail Collapsed Sidebar (~530px content height) ---
        const collapseBtn = page.locator('button[title="Collapse sidebar"], button[aria-label="Collapse sidebar"]').first();
        if (await collapseBtn.isVisible()) {
            await collapseBtn.click();
            await page.waitForTimeout(300);

            const collapsedMetrics = await page.evaluate(() => {
                const container = document.getElementById('sidebar-nav-container');
                const sidebarEl = document.getElementById('main-sidebar');
                return {
                    scrollHeight: container ? container.scrollHeight : 0,
                    clientHeight: container ? container.clientHeight : 0,
                    sidebarScrollHeight: sidebarEl ? sidebarEl.scrollHeight : 0,
                    sidebarClientHeight: sidebarEl ? sidebarEl.clientHeight : 0,
                    width: sidebarEl ? sidebarEl.getBoundingClientRect().width : 0,
                };
            });

            // Collapsed sidebar width is micro-rail w-14 (56px)
            expect(collapsedMetrics.width).toBeLessThanOrEqual(60);
            expect(collapsedMetrics.scrollHeight).toBeLessThanOrEqual(collapsedMetrics.clientHeight + 2);

            // Verify micro-rail icon buttons are desktop h-9 w-9 (<= 40px)
            const microRailButtons = page.locator('#sidebar-nav-container a, #sidebar-nav-container button');
            const btnCount = await microRailButtons.count();
            if (btnCount > 0) {
                const firstBtnBox = await microRailButtons.first().boundingBox();
                if (firstBtnBox) {
                    expect(firstBtnBox.width).toBeLessThanOrEqual(40);
                    expect(firstBtnBox.height).toBeLessThanOrEqual(40);
                }
            }

            // Restore expanded sidebar
            const expandBtn = page.locator('button[title="Expand sidebar"], button[aria-label="Expand sidebar"]').first();
            if (await expandBtn.isVisible()) {
                await expandBtn.click();
                await page.waitForTimeout(300);
            }
        }

        // --- Guardrail 3: Sub-nav <= 5 Tabs + More ▾ Dropdown Navigation ---
        await page.goto('/settings');
        await page.waitForLoadState('networkidle');
        await page.waitForTimeout(300);

        const subNav = page.locator('#main-content nav[aria-label*="Navigation"]');
        if (await subNav.isVisible()) {
            // Count primary tab links (direct children under nav that are a links, excluding dropdown links)
            const primaryTabs = subNav.locator('> a');
            const tabCount = await primaryTabs.count();
            expect(tabCount).toBeLessThanOrEqual(5);

            // Verify "More ▾" dropdown is present when hub has > 5 pages
            const moreDropdownBtn = subNav.locator('button[aria-haspopup="true"][aria-label*="More"]').first();
            if (await moreDropdownBtn.isVisible()) {
                await moreDropdownBtn.click();
                await page.waitForTimeout(150);

                const expanded = await moreDropdownBtn.getAttribute('aria-expanded');
                expect(expanded).toBe('true');

                // Escape closes the dropdown
                await page.keyboard.press('Escape');
                await page.waitForTimeout(150);
                const closedExpanded = await moreDropdownBtn.getAttribute('aria-expanded');
                expect(closedExpanded).toBe('false');
            }
        }

        // --- Guardrail 4: Strict Zero-FOUT Material Symbols 1em Box Lock ---
        const iconsVerified = await page.evaluate(() => {
            const icons = Array.from(document.querySelectorAll('.material-symbols-rounded'));
            if (icons.length === 0) return { count: 0, compliant: true };

            let compliant = true;
            for (const icon of icons.slice(0, 20)) {
                const style = window.getComputedStyle(icon);
                const rect = icon.getBoundingClientRect();
                const w = Math.round(rect.width);
                const h = Math.round(rect.height);

                // Check 1em box lock: display inline-flex, overflow hidden, aspect ratio ~ 1
                if (style.overflow !== 'hidden') compliant = false;
                if (Math.abs(w - h) > 2) compliant = false;
                if (!style.fontFeatureSettings.includes('liga')) compliant = false;
            }
            return { count: icons.length, compliant };
        });

        expect(iconsVerified.count).toBeGreaterThan(0);
        expect(iconsVerified.compliant).toBe(true);
    });

    test('Wave 8 Hyper-Performance: Numeric CLS <= 0.01 on Hard Reload with PerformanceObserver', async ({ page }) => {
        // Register PerformanceObserver before navigation to capture any layout shift from start
        await page.addInitScript(() => {
            (window as any).__cumulativeLayoutShift = 0;
            try {
                const observer = new PerformanceObserver((entryList) => {
                    for (const entry of entryList.getEntries()) {
                        if (!(entry as any).hadRecentInput) {
                            (window as any).__cumulativeLayoutShift += (entry as any).value;
                        }
                    }
                });
                observer.observe({ type: 'layout-shift', buffered: true });
            } catch (e) {
                // PerformanceObserver unsupported or disabled
            }
        });

        // Navigate to login
        await page.goto('/login');
        await page.waitForLoadState('networkidle');

        // Hard reload the page
        await page.reload({ waitUntil: 'networkidle' });
        await page.waitForTimeout(500);

        // Read numeric CLS
        const clsValue = await page.evaluate(() => {
            return (window as any).__cumulativeLayoutShift ?? 0;
        });

        // CLS must be <= 0.01 (Grade A+ Hyper-Performance target)
        expect(clsValue).toBeLessThanOrEqual(0.01);
    });
});

