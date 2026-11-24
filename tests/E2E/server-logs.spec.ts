import type { Page } from '@playwright/test'
import { asMember, blockingViolations, expect, test, useTheme } from './support/fixtures'

function card(page: Page, name: string) {
    return page.getByTestId('server-card').filter({ has: page.getByRole('link', { name, exact: true }) })
}

function lines() {
    const now = Date.now()
    const entry = (
        id: string,
        seconds: number,
        source: string,
        level: string,
        text: string,
        process: string | null = null
    ) => ({
        id,
        at: now - seconds * 1000,
        source,
        process,
        level,
        text,
        more: [],
        request: null,
        href: null
    })

    return {
        pending: false,
        error: null,
        capped: false,
        entries: [
            entry('s1', 50, 'signins', 'info', 'Accepted publickey for deploy from 198.51.100.7 port 51234 ssh2'),
            entry('f1', 40, 'firewall', 'warning', 'fail2ban.actions [811]: NOTICE  [sshd] Ban 203.0.113.9'),
            entry('a1', 30, 'apps', 'error', 'Main process exited, code=exited, status=1/FAILURE', 'shop-worker'),
            entry('y1', 20, 'system', 'error', 'Out of memory: Killed process 4242 (node)')
        ],
        sources: [
            { key: 'requests', label: 'Requests', count: 0 },
            { key: 'apps', label: 'Apps', count: 1 },
            { key: 'web', label: 'Web server', count: 0 },
            { key: 'signins', label: 'Sign-ins', count: 1 },
            { key: 'firewall', label: 'Blocked addresses', count: 1 },
            { key: 'system', label: 'System', count: 1 }
        ],
        stats: { requests: 0, per_minute: null, server_errors: 0, error_rate: null, p50: null, p95: null, slowest: [] },
        processes: []
    }
}

test.describe("a server's logs", () => {
    test('the fleet card opens them, with sign-ins, blocked addresses, app crashes and system warnings', async ({
        page
    }) => {
        await page.route(/\/logs\/lines/, (route) => route.fulfill({ json: lines() }))
        await page.goto('/dashboard')
        await card(page, 'forge-01').getByRole('link', { name: 'Logs' }).click()
        await page.waitForURL(/\/logs$/)

        const timeline = page.getByTestId('logs-timeline')

        await expect(timeline).toContainText('Accepted publickey for deploy')
        await expect(timeline).toContainText('Ban 203.0.113.9')
        await expect(timeline.locator('[data-source="apps"]')).toContainText('shop-worker')
        await expect(timeline).toContainText('Out of memory: Killed process 4242 (node)')
    })

    for (const theme of ['light', 'dark'] as const) {
        test(`they have no serious or critical accessibility violations in ${theme}`, async ({ page }) => {
            await useTheme(page, theme)
            await page.route(/\/logs\/lines/, (route) => route.fulfill({ json: lines() }))
            await page.goto('/dashboard')
            await card(page, 'forge-01').getByRole('link', { name: 'Logs' }).click()
            await expect(page.getByTestId('logs-timeline')).toContainText('Ban 203.0.113.9')

            expect(await blockingViolations(page)).toEqual([])
        })
    }
})

asMember.describe("a server's logs for a member", () => {
    asMember('a member who cannot change the server is taken to its activity instead', async ({ page }) => {
        await page.goto('/dashboard')
        await card(page, 'forge-01').getByRole('link', { name: 'Logs' }).click()

        await page.waitForURL(/\/activity$/)
        await expect(page.getByTestId('server-activity')).toBeVisible()
    })
})
