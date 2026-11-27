import type { Page } from '@playwright/test'
import { readFileSync } from 'node:fs'
import {
    asMember,
    blockingViolations,
    expect,
    isMobile,
    nav,
    noSidewaysScroll,
    test,
    useTheme
} from './support/fixtures'

function events(page: Page) {
    return page.getByTestId('audit-event')
}

async function gotoAuditLog(page: Page) {
    await page.goto('/dashboard')
    await nav(page).getByRole('link', { name: 'Audit log', exact: true }).click()
    await page.waitForURL(/\/audit-log$/)
    await expect(page.getByRole('heading', { level: 1, name: 'Audit log' })).toBeVisible()
}

test.describe('the audit log', () => {
    test('it has its own place in the navigation, just before Settings', async ({ page }) => {
        await page.goto('/dashboard')

        const links = nav(page).getByRole('link')

        await expect(links.filter({ hasText: /^\s*(Releases|Audit log|Settings)\s*$/ })).toHaveText([
            'Releases',
            'Audit log',
            'Settings'
        ])
        await gotoAuditLog(page)
        await expect(nav(page).getByRole('link', { name: 'Audit log', exact: true })).toHaveAttribute(
            'aria-current',
            'page'
        )
    })

    test('each event says what happened, to which thing, by whom and when, with the details on request', async ({
        page
    }) => {
        await gotoAuditLog(page)

        const token = events(page).filter({ hasText: 'API token created' }).first()

        await expect(token).toContainText('Team')
        await expect(token).toContainText(/Monoven/)
        await expect(page.getByTestId('audit-details')).toHaveCount(0)

        await token.getByRole('button', { name: 'Details' }).click()

        const details = token.getByTestId('audit-details')

        await expect(token.getByRole('button', { name: 'Details' })).toHaveAttribute('aria-expanded', 'true')
        await expect(details).toContainText(/At\s+\w{3} \d+, \d{4} at \d{2}:\d{2} UTC/)
        await expect(details).toContainText(/By\s+\S/)
        await expect(details).toContainText(/name\s+\S+/)
    })

    test("coresmith's ssh commands stay hidden until they are asked for, and the address keeps the filter", async ({
        page
    }) => {
        await gotoAuditLog(page)

        await expect(page.getByLabel('What', { exact: true })).toHaveValue('changes')
        await expect(events(page).filter({ hasText: 'SSH command executed' })).toHaveCount(0)

        await page.getByLabel('What', { exact: true }).selectOption('commands')

        await page.waitForURL((url) => url.searchParams.get('what') === 'commands')
        await expect(events(page).first()).toContainText('SSH command executed')
        await expect(events(page).first()).toContainText('forge-01')
        await expect(events(page).filter({ hasText: 'API token created' })).toHaveCount(0)
    })

    test('fifty events a page, then older and newer', async ({ page }) => {
        await gotoAuditLog(page)

        await expect(events(page)).toHaveCount(50)
        await page.getByTestId('pager-older').click()
        await page.waitForURL((url) => url.searchParams.get('page') === '2')
        await expect(events(page).first()).toBeVisible()
        await expect(page.getByTestId('pager-newer')).toBeVisible()
        await noSidewaysScroll(page)
    })

    test('a filter that matches nothing says so and clears in one press', async ({ page }) => {
        await gotoAuditLog(page)

        await page.getByLabel('What', { exact: true }).selectOption('commands')
        await page.waitForURL((url) => url.searchParams.get('what') === 'commands')
        await page.getByLabel('Who', { exact: true }).selectOption({ label: 'Lena Brandt' })
        await page.waitForURL((url) => url.searchParams.has('who'))

        await expect(page.getByText('Nothing matches these filters.')).toBeVisible()
        await page.getByRole('button', { name: 'Clear the filters' }).click()

        await page.waitForURL(/\/audit-log$/)
        await expect(events(page).first()).toBeVisible()
    })

    test('export downloads what the filters show', async ({ page }) => {
        await gotoAuditLog(page)
        await page.getByLabel('What', { exact: true }).selectOption('commands')
        await page.waitForURL((url) => url.searchParams.get('what') === 'commands')

        await expect(page.getByTestId('audit-export')).toHaveAttribute('href', /what=commands/)

        const [download] = await Promise.all([page.waitForEvent('download'), page.getByTestId('audit-export').click()])
        const lines = readFileSync(await download.path(), 'utf8')
            .trim()
            .split('\n')

        expect(download.suggestedFilename()).toMatch(/^coresmith-monoven-audit-\d{4}-\d{2}-\d{2}\.csv$/)
        expect(lines[0]).toBe('time,actor,actor_email,event,subject_type,subject,details')
        expect(lines.slice(1).every((line) => line.includes(',ssh_command_executed,'))).toBe(true)
    })

    test('on a phone each event keeps who and when under it', async ({ page }, testInfo) => {
        test.skip(!isMobile(testInfo), 'From 900px up who and when have their own columns')

        await gotoAuditLog(page)

        await expect(events(page).first()).toContainText(/·\s+\S/)
        await noSidewaysScroll(page)
    })

    for (const theme of ['light', 'dark'] as const) {
        test(`the audit log has no serious or critical accessibility violations in ${theme}`, async ({ page }) => {
            await useTheme(page, theme)
            await gotoAuditLog(page)
            await events(page).first().getByRole('button', { name: 'Details' }).click()
            await expect(page.getByTestId('audit-details').first()).toBeVisible()

            expect(await blockingViolations(page)).toEqual([])
        })
    }
})

asMember.describe('the audit log for a deployer', () => {
    asMember('a deployer has no audit log in the navigation and is turned away from it', async ({ page }) => {
        await page.goto('/dashboard')

        await expect(nav(page).getByRole('link', { name: 'Audit log' })).toHaveCount(0)
        expect((await page.goto('/audit-log'))?.status()).toBe(403)
    })
})
