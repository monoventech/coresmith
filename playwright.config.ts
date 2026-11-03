import { defineConfig, devices } from '@playwright/test'

const PORT = Number(process.env.E2E_PORT ?? 8123)
const baseURL = process.env.APP_URL ?? `http://127.0.0.1:${PORT}`

const authenticated = { storageState: 'tests/E2E/.auth/user.json' }

const INSTALL_PORT = Number(process.env.E2E_INSTALL_PORT ?? 8124)
const installBaseURL = `http://127.0.0.1:${INSTALL_PORT}`

const CLOUD_PORT = Number(process.env.E2E_CLOUD_PORT ?? 8127)
const cloudBaseURL = `http://127.0.0.1:${CLOUD_PORT}`
const STRIPE_PORT = Number(process.env.E2E_STRIPE_PORT ?? 8126)

const cloudOwner = { baseURL: cloudBaseURL, storageState: 'tests/E2E/.auth/cloud-owner.json' }
const plainTests = [/installer\.spec\.ts/, /tests\/E2E\/cloud\//]
const cloudTests = /tests\/E2E\/cloud\/.*\.spec\.ts/

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
        { name: 'setup', testMatch: /auth\.setup\.ts/ },
        { name: 'cloud-setup', testMatch: /cloud\.setup\.ts/, use: { baseURL: cloudBaseURL } },

        {
            name: 'installer',
            testMatch: /installer\.spec\.ts/,
            use: { ...devices['Desktop Chrome'], baseURL: installBaseURL, storageState: { cookies: [], origins: [] } },
        },

        {
            name: 'chromium',
            testIgnore: plainTests,
            use: { ...devices['Desktop Chrome'], ...authenticated },
            dependencies: ['setup'],
        },
        {
            name: 'firefox',
            testIgnore: plainTests,
            use: { ...devices['Desktop Firefox'], ...authenticated },
            dependencies: ['setup'],
        },
        {
            name: 'webkit',
            testIgnore: plainTests,
            use: { ...devices['Desktop Safari'], ...authenticated },
            dependencies: ['setup'],
        },
        {
            name: 'mobile-chrome',
            testIgnore: plainTests,
            use: { ...devices['Pixel 5'], ...authenticated },
            dependencies: ['setup'],
        },
        {
            name: 'mobile-safari',
            testIgnore: plainTests,
            use: { ...devices['iPhone 13'], ...authenticated },
            dependencies: ['setup'],
        },

        {
            name: 'cloud-chromium',
            testMatch: cloudTests,
            use: { ...devices['Desktop Chrome'], ...cloudOwner },
            dependencies: ['cloud-setup'],
        },
        {
            name: 'cloud-firefox',
            testMatch: cloudTests,
            use: { ...devices['Desktop Firefox'], ...cloudOwner },
            dependencies: ['cloud-setup'],
        },
        {
            name: 'cloud-webkit',
            testMatch: cloudTests,
            use: { ...devices['Desktop Safari'], ...cloudOwner },
            dependencies: ['cloud-setup'],
        },
        {
            name: 'cloud-mobile-chrome',
            testMatch: cloudTests,
            use: { ...devices['Pixel 5'], ...cloudOwner },
            dependencies: ['cloud-setup'],
        },
        {
            name: 'cloud-mobile-safari',
            testMatch: cloudTests,
            use: { ...devices['iPhone 13'], ...cloudOwner },
            dependencies: ['cloud-setup'],
        },
    ],

    webServer: [
        {
            command: `php artisan serve --no-reload --port=${PORT} --env=e2e`,
            env: { APP_URL: baseURL },
            url: baseURL,
            reuseExistingServer: !process.env.CI,
            timeout: 30_000,
        },
        {
            command: `php artisan serve --no-reload --port=${INSTALL_PORT} --env=e2e-install`,
            env: { APP_URL: baseURL },
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
        {
            command: `php artisan serve --no-reload --port=${CLOUD_PORT} --env=e2e-cloud`,
            env: { APP_URL: cloudBaseURL, CORESMITH_STRIPE_API_BASE: `http://127.0.0.1:${STRIPE_PORT}` },
            url: `${cloudBaseURL}/up`,
            reuseExistingServer: !process.env.CI,
            timeout: 30_000,
        },
        {
            command: 'bun tests/E2E/support/stripe-fake.ts',
            url: `http://127.0.0.1:${STRIPE_PORT}/health`,
            reuseExistingServer: !process.env.CI,
            timeout: 30_000,
        },
    ],
})
