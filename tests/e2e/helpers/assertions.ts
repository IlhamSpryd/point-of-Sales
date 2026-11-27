import { BrowserContext, expect, Page } from '@playwright/test';

export async function stubExternalMidtransAssets(target: Page | BrowserContext): Promise<void> {
    await target.route('https://*.midtrans.com/**', async (route) => {
        const url = route.request().url();
        if (url.includes('/snap/snap.js') || url.includes('snap')) {
            await route.fulfill({
                status: 200,
                contentType: 'application/javascript',
                body: 'window.snap = window.snap || { pay: function () {} };',
            });

            return;
        }

        await route.abort();
    });

    // Headless E2E runners do not have QZ Tray thermal printer daemon listening on localhost:8182..8485
    await target.route('**/vendor/qz-tray.js*', async (route) => {
        await route.fulfill({
            status: 200,
            contentType: 'application/javascript',
            body: '/* QZ Tray unavailable in headless E2E */',
        });
    });
}

export function attachStrictConsoleGuard(page: Page, options: { allowOfflineNetworkErrors?: boolean } = {}): () => void {
    const errors: string[] = [];

    const onPageError = (err: Error): void => {
        errors.push(`pageerror: ${err.message}`);
    };

    const onConsole = (msg: { type(): string; text(): string }): void => {
        if (msg.type() !== 'error') {
            return;
        }
        const text = msg.text();
        if (
            options.allowOfflineNetworkErrors
            && (
                text.includes('ERR_INTERNET_DISCONNECTED')
                || text.includes('net::ERR_FAILED')
                || text.includes('Failed to load resource')
                || text.includes('Failed to fetch')
            )
        ) {
            return;
        }
        if (text.includes('ReferenceError') || text.includes('TypeError') || !options.allowOfflineNetworkErrors) {
            errors.push(`console.error: ${text}`);
        }
    };

    page.on('pageerror', onPageError);
    page.on('console', onConsole);

    return () => {
        page.off('pageerror', onPageError);
        page.off('console', onConsole);
        expect(errors, `Unexpected browser runtime/console errors:\n${errors.join('\n')}`).toEqual([]);
    };
}

