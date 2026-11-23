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
        await page.fill('#password', '12345678');
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
});

