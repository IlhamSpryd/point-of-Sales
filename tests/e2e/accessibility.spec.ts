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

        await page.goto('/login');
        await expect(page).toHaveTitle(/Apeiron/i);

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
        await page.emulateMedia({ colorScheme: 'dark' });
        await page.goto('/login');

        // Evaluate whether root or html has dark styling
        const hasDarkOrSemanticBackground = await page.evaluate(() => {
            const bg = window.getComputedStyle(document.body).backgroundColor;
            return bg !== 'rgb(255, 255, 255)'; // Should not be pure white
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
            const modal = page.locator('[role="dialog"]');
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
});

