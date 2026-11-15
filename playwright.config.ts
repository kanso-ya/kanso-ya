import { defineConfig, devices } from '@playwright/test';

/**
 * Configuration Playwright pour le CMS Kanso-ya.
 * Adaptée pour le vhost Laragon avec certificat auto-signé.
 */
export default defineConfig({
    testDir: './tests/e2e',
    fullyParallel: true,
    forbidOnly: !!process.env.CI,
    retries: process.env.CI ? 2 : 0,
    workers: process.env.CI ? 1 : undefined,
    globalSetup: './tests/setup/global-setup.ts',
    globalTeardown: './tests/setup/global-teardown.ts',
    reporter: [
        ['list'],
        ['allure-playwright', { detail: true, outputFolder: 'allure-results' }],
        ['./tests/reporters/MarkdownErrorReporter.ts']
    ],
    use: {
        // URL virtuelle générée par Laragon
        baseURL: 'https://kanso-ya.fr',
        
        // Autorise les certificats auto-signés de Laragon en local
        ignoreHTTPSErrors: true,
        
        trace: 'on-first-retry',
        video: 'retain-on-failure',
    },
    projects: [
        { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
        { name: 'Mobile Chrome', use: { ...devices['Pixel 5'] } },
    ],
});