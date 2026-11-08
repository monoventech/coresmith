import type { Page } from '@playwright/test'
import { dialog, expect, isMobile, main, NARROW_PHONE, nav, own, test, toast } from './support/fixtures'

function card(page: Page, name: string) {
    return page.getByTestId('server-card').filter({ has: page.getByRole('link', { name, exact: true }) })
}

test.describe('the fleet', () => {
    test('the heading counts what the team runs', async ({ page }) => {
        await page.goto('/dashboard')

        await expect(page.getByRole('heading', { level: 1, name: 'Your fleet' })).toBeVisible()
        await expect(main(page).getByText(/^\d+ servers · \d+ applications/)).toBeVisible()
        await expect(nav(page).getByRole('link', { name: 'Fleet' })).toHaveAttribute('aria-current', 'page')
    })

    test('a deploy in progress is pinned above the fleet and opens the live deploy', async ({ page }) => {
        await page.goto('/dashboard')

        const live = page.getByTestId('live-deploy')

        await expect(live).toContainText('atlas-staging is deploying')
        await live.click()

        await page.waitForURL(/\/deployments\/[A-Za-z0-9]+/)
        await expect(page.getByRole('heading', { level: 1, name: 'Deploying atlas-staging' })).toBeVisible()
        await expect(page.getByTestId('back-link')).toHaveText('← Fleet')
    })

    test('every server card opens its server', async ({ page }) => {
        await page.goto('/dashboard')

        await card(page, 'forge-01').getByRole('link', { name: 'forge-01', exact: true }).click()

        await page.waitForURL(/\/servers\/[A-Za-z0-9]+$/)
        await expect(page.getByRole('heading', { level: 1, name: 'forge-01' })).toBeVisible()
    })

    test('a card says what state its server is in', async ({ page }) => {
        await page.goto('/dashboard')

        await expect(card(page, 'forge-01')).toContainText('Healthy')
        await expect(card(page, 'forge-02')).toContainText('Deploying')
        await expect(card(page, 'outpost-01')).toContainText('Disk 84%')
        await expect(card(page, 'rekeyed-server')).toContainText('Host key changed')
        await expect(card(page, 'empty-server')).toContainText('Not connected')
        await expect(card(page, 'empty-server')).toContainText('Staging · ams3 · 0 apps')
    })

    test('a long server name wraps before its state label does on the narrowest phone', async ({ page }) => {
        await page.setViewportSize(NARROW_PHONE)
        await page.goto('/dashboard')
        await expect(card(page, 'rekeyed-server')).toContainText('Host key changed')

        const layout = await card(page, 'rekeyed-server').evaluate((element) => {
            const name = element.querySelector('a.card-link')
            const label = element.querySelector('[data-testid="server-state"]')

            if (name === null || label === null) {
                return null
            }

            name.textContent = 'rekeyed-production-web-server-01'

            const text = document.createRange()
            text.selectNodeContents(label)

            return { lines: text.getClientRects().length, overflow: element.scrollWidth - element.clientWidth }
        })

        expect(layout).toEqual({ lines: 1, overflow: 0 })
    })

    test('a server with fresh readings shows them rather than dashes', async ({ page }, testInfo) => {
        await page.goto('/dashboard')

        const forge = card(page, 'forge-01')

        if (isMobile(testInfo)) {
            await expect(forge).toContainText(/CPU \d+%/)
            await expect(forge).toContainText(/DISK \d+%/)

            return
        }

        await expect(forge.getByText('CPU', { exact: true })).toBeVisible()
        await expect(forge).toContainText(/\d+%/)
    })

    test('the summary tiles lead to what they count', async ({ page }, testInfo) => {
        test.skip(isMobile(testInfo), 'The tiles are hidden below 640px; the phone Fleet keeps only the cards')

        await page.goto('/dashboard')

        await expect(page.getByTestId('tile-applications')).toContainText('Applications')
        await expect(page.getByTestId('tile-deploys')).toContainText('Deploys this week')
        await expect(page.getByTestId('tile-uptime')).toContainText('Uptime, 30 days')

        const attention = page.getByTestId('tile-attention')

        await expect(attention).toContainText('Needs attention')
        await attention.click()
        await page.waitForURL(/\/servers\/[A-Za-z0-9]+/)
    })

    test('recent activity is listed and each entry leads somewhere', async ({ page }, testInfo) => {
        test.skip(isMobile(testInfo), 'The activity feed is hidden below 640px; the phone Fleet keeps only the cards')

        await page.goto('/dashboard')

        const activity = page.getByRole('region', { name: 'Recent activity' })

        await expect(activity.getByRole('link').first()).toBeVisible()
    })

    test('a panel update in the activity leads to Settings', async ({ page }, testInfo) => {
        test.skip(isMobile(testInfo), 'The activity feed is hidden below 640px; the phone Fleet keeps only the cards')

        await page.goto('/dashboard')

        const update = page
            .getByRole('region', { name: 'Recent activity' })
            .getByRole('link', { name: /Auto-update applied, Coresmith v0\.1\.1, backup taken, health check passed/ })

        await expect(update).toBeVisible()
        await update.click()
        await page.waitForURL(/\/settings$/)
    })

    test('on a phone the fleet keeps the cards and drops the tiles and feed', async ({ page }, testInfo) => {
        test.skip(!isMobile(testInfo), 'The compact Fleet only renders below 640px')

        await page.goto('/dashboard')

        await expect(page.getByTestId('tile-applications')).toBeHidden()
        await expect(page.getByRole('region', { name: 'Recent activity' })).toBeHidden()
        await expect(page.getByTestId('mobile-nav')).toBeVisible()
        await expect(page.getByTestId('sidebar')).toBeHidden()
        await expect(page.getByRole('link', { name: 'Provision a server' })).toBeHidden()
    })
})

test.describe('quick actions on a server card', () => {
    test.skip(({ isMobile }) => isMobile, 'Quick actions are hidden below 640px; a phone opens the server instead')

    test('a server with a running deploy offers to watch it', async ({ page }) => {
        await page.goto('/dashboard')
        await card(page, 'forge-02').getByRole('link', { name: 'Watch deploy' }).click()

        await expect(page.getByRole('heading', { level: 1, name: 'Deploying atlas-staging' })).toBeVisible()
    })

    test('a provider server shows what it costs a month', async ({ page }) => {
        await page.goto('/dashboard')

        await expect(card(page, 'outpost-01')).toContainText('$12/mo')
    })

    test('a server being created offers to continue setup', async ({ page }) => {
        await page.goto('/dashboard')

        await expect(card(page, 'creating-server').getByRole('link', { name: 'Continue setup' })).toBeVisible()
    })

    test('a server that is not connected offers to continue setup', async ({ page }) => {
        await page.goto('/dashboard')
        await card(page, 'empty-server').getByRole('link', { name: 'Continue setup' }).click()

        await expect(page.getByRole('heading', { level: 1, name: 'empty-server' })).toBeVisible()
        await expect(page.getByTestId('provisioning-phases')).toBeVisible()
    })

    test('a server without apps offers to add one', async ({ page }, testInfo) => {
        const server = own(testInfo, 'rename')

        await page.goto('/dashboard')
        await card(page, server).getByRole('link', { name: 'Add an app' }).click()

        await expect(page.getByRole('heading', { level: 1, name: 'Add an app' })).toBeVisible()
        await expect(page.getByTestId('back-link')).toHaveText(`← ${server}`)
    })

    test('logs open the server activity', async ({ page }) => {
        await page.goto('/dashboard')
        await card(page, 'forge-01').getByRole('link', { name: 'Logs' }).click()

        await page.waitForURL(/\/activity$/)
        await expect(page.getByTestId('server-activity')).toBeVisible()
    })

    test('deploying from a busy server asks which app, then opens the deploy', async ({ page }, testInfo) => {
        const application = own(testInfo, 'scratch')

        await page.goto('/dashboard')
        await card(page, 'bench-01').getByRole('button', { name: 'Deploy' }).click()

        await expect(dialog(page)).toContainText('Deploy on bench-01')

        await dialog(page)
            .getByTestId('deploy-option')
            .filter({ hasText: application })
            .getByRole('button', { name: 'Deploy' })
            .click()

        await expect(page.getByRole('heading', { level: 1, name: `Deploying ${application}` })).toBeVisible()
        await expect(page.getByTestId('back-link')).toHaveText('← Fleet')
        await expect(toast(page)).toContainText(`Deploying ${application}.`)
    })

    test('the deploy picker can be dismissed without deploying', async ({ page }) => {
        await page.goto('/dashboard')
        await card(page, 'forge-01').getByRole('button', { name: 'Deploy' }).click()

        await expect(dialog(page)).toContainText('Deploy on forge-01')
        await dialog(page).getByRole('button', { name: 'Cancel' }).click()

        await expect(dialog(page)).toHaveCount(0)
        await expect(page).toHaveURL(/\/dashboard$/)
    })
})
