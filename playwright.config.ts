import { defineConfig, devices } from '@playwright/test'

const PORT = Number(process.env.E2E_PORT ?? 8123)
const baseURL = process.env.APP_URL ?? `http://127.0.0.1:${PORT}`

const authenticated = { storageState: 'tests/E2E/.auth/user.json' }

const INSTALL_PORT = Number(process.env.E2E_INSTALL_PORT ?? 8124)
const installBaseURL = `http://127.0.0.1:${INSTALL_PORT}`

export default defineConfig({
    testDir: './tests/E2E',
    fullyParallel: false,
    forbidOnly: !!process.env.CI,
    retries: process.env.CI ? 2 : 0,
    workers: 1,
    reporter: process.env.CI ? 'github' : [['list'], ['html', { open: 'never' }]],
    timeout: 30_000,

    use: {
        baseURL,
        trace: 'on-first-retry',
        screenshot: 'only-on-failure',
        video: 'on-first-retry',
    },

    projects: [
        { name: 'setup', testMatch: /.*\.setup\.ts/ },

        {
            name: 'installer',
            testMatch: /installer\.spec\.ts/,
            use: { ...devices['Desktop Chrome'], baseURL: installBaseURL, storageState: { cookies: [], origins: [] } },
        },

        {
            name: 'chromium',
            testIgnore: /installer\.spec\.ts/,
            use: { ...devices['Desktop Chrome'], ...authenticated },
            dependencies: ['setup'],
        },
        {
            name: 'firefox',
            testIgnore: /installer\.spec\.ts/,
            use: { ...devices['Desktop Firefox'], ...authenticated },
            dependencies: ['setup'],
        },
        {
            name: 'webkit',
            testIgnore: /installer\.spec\.ts/,
            use: { ...devices['Desktop Safari'], ...authenticated },
            dependencies: ['setup'],
        },
        {
            name: 'mobile-chrome',
            testIgnore: /installer\.spec\.ts/,
            use: { ...devices['Pixel 5'], ...authenticated },
            dependencies: ['setup'],
        },
        {
            name: 'mobile-safari',
            testIgnore: /installer\.spec\.ts/,
            use: { ...devices['iPhone 13'], ...authenticated },
            dependencies: ['setup'],
        },
    ],

    webServer: [
        {
            command: `php artisan serve --port=${PORT} --env=e2e`,
            url: baseURL,
            reuseExistingServer: !process.env.CI,
            timeout: 30_000,
        },
        {
            command: `php artisan serve --port=${INSTALL_PORT} --env=e2e-install`,
            url: `${installBaseURL}/up`,
            reuseExistingServer: !process.env.CI,
            timeout: 30_000,
        },
        {
            command: 'bun tests/E2E/support/release-feed.ts',
            url: 'http://127.0.0.1:8125/v1/version',
            reuseExistingServer: !process.env.CI,
            timeout: 30_000,
        },
    ],
})
