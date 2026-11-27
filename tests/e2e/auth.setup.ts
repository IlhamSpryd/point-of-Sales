import { test as setup, expect } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';

const authDir = path.resolve(process.cwd(), 'playwright/.auth');
const password = process.env.E2E_PASSWORD || 'password123';

interface AuthAccount {
    key: string;
    email: string;
    expectedUrlPattern: RegExp;
}

const accounts: AuthAccount[] = [
    { key: 'owner', email: 'admin@pos.test', expectedUrlPattern: /(dashboard|home)/i },
    { key: 'cashier', email: 'kasir@pos.test', expectedUrlPattern: /(transaction|shifts|dashboard)/i },
    { key: 'cashier2', email: 'kasir2@pos.test', expectedUrlPattern: /(transaction|shifts|dashboard)/i },
    { key: 'supervisor', email: 'supervisor@pos.test', expectedUrlPattern: /(shifts|orders|dashboard|workflow)/i },
    { key: 'staff', email: 'staff@pos.test', expectedUrlPattern: /(dashboard|orders|transaction|shifts)/i },
    { key: 'tenant-b', email: 'admin.b@pos.test', expectedUrlPattern: /(dashboard|home)/i },
];

setup('authenticate Tier1DemoSeeder role sessions', async ({ browser }) => {
    fs.mkdirSync(authDir, { recursive: true });

    for (const account of accounts) {
        const context = await browser.newContext({
            viewport: { width: 1366, height: 768 },
        });
        const page = await context.newPage();

        await page.goto('/login');
        await page.waitForSelector('#email');
        await page.fill('#email', account.email);
        await page.fill('#password', password);
        await page.click('button[type="submit"]');
        await page.waitForURL((url) => !url.pathname.endsWith('/login'), { timeout: 20000 });
        await page.waitForLoadState('networkidle');

        if (account.key !== 'staff') {
            await expect(page.locator('#main-sidebar')).toBeVisible();
        }
        await context.storageState({ path: path.join(authDir, `${account.key}.json`) });
        await context.close();
    }
});

