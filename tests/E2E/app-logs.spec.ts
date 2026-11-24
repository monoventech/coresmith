import type { Page, Request } from '@playwright/test'
import { blockingViolations, expect, gotoApplication, isMobile, test, useTheme } from './support/fixtures'

type Overrides = { error?: string | null; entries?: Array<Record<string, unknown>>; extra?: number; capped?: boolean }

function entry(id: string, seconds: number, fields: Record<string, unknown>) {
    return {
        id,
        at: Date.now() - seconds * 1000,
        source: 'process:worker',
        process: 'worker',
        level: 'info',
        more: [],
        request: null,
        href: null,
        ...fields
    }
}

function request(id: string, seconds: number, method: string, path: string, status: number) {
    return entry(id, seconds, {
        source: 'requests',
        process: null,
        level: status >= 500 ? 'error' : 'info',
        text: `${method} ${path} ${status} 40ms`,
        request: {
            method,
            path,
            status,
            ms: 40,
            bytes: 2048,
            ip: '203.0.113.42',
            agent: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_5) Version/18.1 Safari/605.1.15',
            referer: 'https://storefront.example.com/checkout',
            host: 'storefront.example.com',
            proto: 'HTTP/2.0'
        }
    })
}

function lines(overrides: Overrides = {}) {
    const extra = Array.from({ length: overrides.extra ?? 0 }, (_, index) =>
        entry(`extra-${index}`, 1, { text: `Processing SyncInventory, job ${5600 + index}` })
    )

    return {
        pending: false,
        error: overrides.error ?? null,
        capped: overrides.capped ?? false,
        entries: overrides.entries ?? [
            request('r1', 60, 'GET', '/checkout', 200),
            entry('w1', 50, { text: 'Processing SendReceipt, job 5521' }),
            request('r2', 40, 'POST', '/api/pay', 500),
            entry('p1', 40, {
                source: 'php',
                process: null,
                level: 'error',
                text: 'PHP Fatal error:  Uncaught PDOException',
                more: ['Stack trace:', '#0 {main}']
            }),
            ...extra
        ],
        sources: [
            { key: 'requests', label: 'Requests', count: 2 },
            { key: 'php', label: 'PHP errors', count: 1 },
            { key: 'process:worker', label: 'worker', count: 1 + extra.length },
            { key: 'deploys', label: 'Deploys', count: 0 }
        ],
        stats: {
            requests: 1284,
            per_minute: 21.4,
            server_errors: 10,
            error_rate: 0.8,
            p50: 41,
            p95: 182,
            slowest: [{ path: 'GET /api/report', ms: 2100, count: 4 }]
        },
        processes: [
            {
                process: 'worker',
                state: { key: 'running', label: 'Running', tone: 'success' },
                memory: '96 MB',
                restarts: 0,
                since: 'today 11:40'
            }
        ]
    }
}

async function fakeLines(page: Page, answer: (request: Request) => Overrides = () => ({})) {
    const asked: URL[] = []

    await page.route(/\/logs\/lines/, (route) => {
        asked.push(new URL(route.request().url()))

        return route.fulfill({ json: lines(answer(route.request())) })
    })

    return asked
}

async function openLogs(page: Page, mobile: boolean) {
    await gotoApplication(page, 'forge-01', 'storefront')
    await page.getByTestId(mobile ? 'open-logs-phone' : 'open-logs').click()
    await page.waitForURL(/\/logs/)
    await expect(page.getByRole('heading', { level: 1, name: 'Logs' })).toBeVisible()
}

function timeline(page: Page) {
    return page.getByTestId('logs-timeline')
}

test.describe("an app's logs", () => {
    test('the app page opens its logs, with requests, process output, errors and how the app is doing', async ({
        page
    }, testInfo) => {
        await fakeLines(page)
        await openLogs(page, isMobile(testInfo))

        await expect(timeline(page)).toContainText('POST /api/pay 500')
        await expect(timeline(page)).toContainText('Processing SendReceipt, job 5521')
        await expect(timeline(page)).toContainText('PHP Fatal error:  Uncaught PDOException')
        await expect(page.getByTestId('logs-glance')).toContainText('1,284')
        await expect(page.getByTestId('logs-glance')).toContainText('0.8%')
        await expect(page.getByTestId('logs-processes')).toContainText('Running since today 11:40, 96 MB')
        await expect(page.getByTestId('logs-status')).toHaveText('Live, following')
    })

    test('a stack trace opens under its line', async ({ page }, testInfo) => {
        await fakeLines(page)
        await openLogs(page, isMobile(testInfo))

        await timeline(page).getByRole('button', { name: '2 more lines' }).click()

        await expect(timeline(page)).toContainText('#0 {main}')
        await expect(timeline(page).getByRole('button', { name: 'Show less' })).toHaveAttribute('aria-expanded', 'true')
    })

    test('turning a source off asks for the others and keeps the choice in the address', async ({ page }, testInfo) => {
        const asked = await fakeLines(page)

        await openLogs(page, isMobile(testInfo))

        if (isMobile(testInfo)) {
            await page.getByTestId('logs-filters').click()
        }

        await page
            .getByRole('dialog')
            .or(page.getByTestId('logs-sources'))
            .getByRole('button', { name: /^Requests/ })
            .click()

        await expect
            .poll(() => asked.at(-1)?.searchParams.getAll('sources[]'))
            .toEqual(['php', 'process:worker', 'deploys'])
        await expect(page).toHaveURL(/sources%5B%5D=php/)
    })

    test('errors only is asked of the server', async ({ page }, testInfo) => {
        const asked = await fakeLines(page)

        await openLogs(page, isMobile(testInfo))

        if (isMobile(testInfo)) {
            await page.getByTestId('logs-filters').click()
        }

        await page.getByRole('button', { name: 'Errors', exact: true }).click()

        await expect.poll(() => asked.at(-1)?.searchParams.get('level')).toBe('errors')
        await expect(page).toHaveURL(/level=errors/)
    })

    test('a search asks the server, and following pauses while it is on', async ({ page }, testInfo) => {
        const asked = await fakeLines(page)

        await openLogs(page, isMobile(testInfo))
        await page.getByLabel('Search logs').fill('checkout')

        await expect.poll(() => asked.at(-1)?.searchParams.get('q')).toBe('checkout')
        await expect(page.getByTestId('logs-status')).toHaveText('Paused while you search')
    })

    test('a request opens its details, and Show logs around this narrows to that moment', async ({
        page
    }, testInfo) => {
        const asked = await fakeLines(page)

        await openLogs(page, isMobile(testInfo))
        await timeline(page)
            .getByRole('button', { name: /POST \/api\/pay/ })
            .click()

        const details = page.getByTestId('logs-request')

        await expect(details).toContainText('500, server error')
        await expect(details).toContainText('203.0.113.42')
        await expect(details).toContainText('/checkout')
        await expect(details).toContainText('Safari 18 on macOS')
        await expect(page.getByTestId('logs-related')).toHaveText('A PHP error was logged in the same second.')

        await page.getByTestId('logs-around').click()

        await expect.poll(() => asked.at(-1)?.searchParams.get('around')).toMatch(/^\d+$/)
        await expect(page.getByTestId('logs-around-note')).toContainText('Showing ten seconds either side of')
        await page.getByRole('button', { name: 'Back to live' }).click()
        await expect(page.getByTestId('logs-status')).toHaveText('Live, following')
    })

    test('new lines arrive while following, and while you read earlier ones a button counts them', async ({
        page
    }, testInfo) => {
        let extra = 0

        await fakeLines(page, () => ({ extra }))
        await openLogs(page, isMobile(testInfo))
        await expect(timeline(page)).toContainText('Processing SendReceipt')

        await timeline(page).evaluate((element) => (element.scrollTop = 0))
        extra = 3

        const jump = page.getByTestId('logs-new')

        await expect(jump).toHaveText('3 new lines', { timeout: 6_000 })
        await jump.click()
        await expect(jump).toBeHidden()
        await expect(timeline(page)).toContainText('Processing SyncInventory, job 5602')
    })

    test('Pause stops following and Resume picks it up again', async ({ page }, testInfo) => {
        const asked = await fakeLines(page)

        await openLogs(page, isMobile(testInfo))
        await page.getByTestId('logs-pause').click()
        await expect(page.getByTestId('logs-status')).toHaveText('Paused')

        const count = asked.length

        await page.waitForTimeout(2_500)
        expect(asked.length).toBe(count)

        await page.getByTestId('logs-pause').click()
        await expect(page.getByTestId('logs-status')).toHaveText('Live, following')
        await expect.poll(() => asked.length).toBeGreaterThan(count)
    })

    test('a server that cannot be reached says so and keeps the lines it had', async ({ page }, testInfo) => {
        await fakeLines(page, () => ({
            error: "Couldn't reach forge-01 to read the logs. Coresmith tries again in a moment."
        }))
        await openLogs(page, isMobile(testInfo))

        await expect(page.getByTestId('logs-error')).toHaveText(
            "Couldn't reach forge-01 to read the logs. Coresmith tries again in a moment."
        )
        await expect(timeline(page)).toContainText('POST /api/pay 500')
    })

    test('a busy read says only the newest lines are shown and that a search finds older ones', async ({
        page
    }, testInfo) => {
        await fakeLines(page, () => ({ capped: true }))
        await openLogs(page, isMobile(testInfo))

        await expect(page.getByTestId('logs-capped')).toHaveText(
            'Showing the newest lines only. Search to find older ones.'
        )
    })

    test('an hour with nothing in it offers the last 24 hours', async ({ page }, testInfo) => {
        const asked = await fakeLines(page, () => ({ entries: [] }))

        await openLogs(page, isMobile(testInfo))
        await expect(page.getByTestId('logs-empty')).toContainText('Nothing logged in last hour.')
        await page.getByRole('button', { name: 'Look at the last 24 hours' }).click()

        await expect.poll(() => asked.at(-1)?.searchParams.get('window')).toBe('24h')
    })

    for (const theme of ['light', 'dark'] as const) {
        test(`the logs page and a request's details have no serious or critical accessibility violations in ${theme}`, async ({
            page
        }, testInfo) => {
            await useTheme(page, theme)
            await fakeLines(page)
            await openLogs(page, isMobile(testInfo))
            await timeline(page)
                .getByRole('button', { name: /POST \/api\/pay/ })
                .click()
            await expect(page.getByTestId('logs-request')).toBeVisible()

            expect(await blockingViolations(page)).toEqual([])
        })
    }
})
