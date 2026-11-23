import { defineConfig, devices } from '@playwright/test';

/**
 * Apeiron Systems — Playwright E2E & Accessibility Configuration (Wave 2D)
 * Targets local server at 127.0.0.1:8000 with desktop 14" and tablet POS viewports.
 */
export default defineConfig({
    testDir: './tests/e2e',
    timeout: 30000,
    fullyParallel: true,
    forbidOnly: !!process.env.CI,
    retries: process.env.CI ? 2 : 0,
    workers: process.env.CI ? 1 : undefined,
    reporter: process.env.CI ? [['github'], ['list'], ['html', { open: 'never' }]] : 'list',
    use: {
        baseURL: process.env.APP_URL || 'http://127.0.0.1:8000',
        trace: 'on-first-retry',
        screenshot: 'only-on-failure',
    },
    projects: [
        {
            name: 'Desktop 14-inch Light',
            use: {
                viewport: { width: 1366, height: 768 },
                colorScheme: 'light',
            },
        },
        {
            name: 'Desktop 14-inch Dark',
            use: {
                viewport: { width: 1366, height: 768 },
                colorScheme: 'dark',
            },
        },
        {
            name: 'Tablet POS (iPad)',
            use: {
                viewport: { width: 1024, height: 768 },
                colorScheme: 'light',
                hasTouch: true,
            },
        },
    ],
    webServer: {
        command: 'php artisan serve --host=127.0.0.1 --port=8000',
        url: 'http://127.0.0.1:8000/login',
        reuseExistingServer: true,
        timeout: 120000,
    },
});

