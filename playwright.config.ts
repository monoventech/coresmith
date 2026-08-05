import { defineConfig, devices } from '@playwright/test'

const PORT = Number(process.env.E2E_PORT ?? 8123)
const baseURL = process.env.APP_URL ?? `http://127.0.0.1:${PORT}`

const authenticated = { storageState: 'tests/E2E/.auth/user.json' }

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
            name: 'chromium',
            use: { ...devices['Desktop Chrome'], ...authenticated },
            dependencies: ['setup'],
        },
        {
            name: 'firefox',
            use: { ...devices['Desktop Firefox'], ...authenticated },
            dependencies: ['setup'],
        },
        {
            name: 'webkit',
            use: { ...devices['Desktop Safari'], ...authenticated },
            dependencies: ['setup'],
        },
        {
            name: 'mobile-chrome',
            use: { ...devices['Pixel 5'], ...authenticated },
            dependencies: ['setup'],
        },
        {
            name: 'mobile-safari',
            use: { ...devices['iPhone 13'], ...authenticated },
            dependencies: ['setup'],
        },
    ],

    webServer: {
        command: `php artisan serve --port=${PORT} --env=e2e`,
        url: baseURL,
        reuseExistingServer: !process.env.CI,
        timeout: 30_000,
    },
})
