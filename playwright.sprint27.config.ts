import { defineConfig, devices } from '@playwright/test';

/**
 * Configuration Playwright dédiée au Sprint 27 (Lot 7 — QA Intégrale 100 % UI, Audit Vues & Mailpit).
 * Matrice complète Desktop (Chromium, Firefox, WebKit) & Mobile (Android Pixel/Galaxy + iOS iPhone/iPhone Pro).
 */
const iphoneProDevice = (devices as Record<string, (typeof devices)['iPhone 13']>)['iPhone 15 Pro']
    ?? (devices as Record<string, (typeof devices)['iPhone 13']>)['iPhone 14 Pro']
    ?? devices['iPhone 13 Pro'];

export default defineConfig({
    testDir: './tests/e2e',
    testMatch: /28-sprint27-.*\.spec\.ts/,
    fullyParallel: false,
    forbidOnly: !!process.env.CI,
    retries: 1,
    workers: 1,
    timeout: 60_000,
    globalSetup: './tests/setup/global-setup.ts',
    globalTeardown: './tests/setup/global-teardown.ts',
    reporter: [
        ['list'],
        ['./tests/reporters/MarkdownErrorReporter.ts'],
    ],
    use: {
        baseURL: 'https://kanso-ya.fr',
        ignoreHTTPSErrors: true,
        trace: 'on-first-retry',
        video: 'retain-on-failure',
    },
    projects: [
        { name: 'Desktop Chromium', use: { ...devices['Desktop Chrome'] } },
        { name: 'Desktop Firefox', use: { ...devices['Desktop Firefox'] } },
        { name: 'Desktop WebKit', use: { ...devices['Desktop Safari'] } },
        { name: 'Mobile Pixel 5', use: { ...devices['Pixel 5'] } },
        { name: 'Mobile Galaxy S9+', use: { ...devices['Galaxy S9+'] } },
        { name: 'Mobile iPhone 13', use: { ...devices['iPhone 13'] } },
        { name: 'Mobile iPhone Pro', use: { ...iphoneProDevice } },
    ],
});
