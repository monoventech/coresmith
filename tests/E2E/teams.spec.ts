import type { Page } from '@playwright/test'
import { asMember, dialog, expect, isMobile, nav, own, tabs, test, toast } from './support/fixtures'

async function backToMonoven(page: Page) {
    await page.goto('/monoven/dashboard')
    await expect(page.getByRole('heading', { level: 1, name: 'Your fleet' })).toBeVisible()
}

test.afterEach(async ({ page }) => {
    await backToMonoven(page)
})

test.describe('switching teams', () => {
    test('the account dialog lists every team and switches into another', async ({ page }) => {
        await page.goto('/monoven/dashboard')
        await page.getByRole('button', { name: 'Account' }).click()

        const switcher = dialog(page).getByTestId('team-switcher')

        await expect(switcher).toContainText('Monoven Ltd')
        await expect(switcher).toContainText('current')

        await switcher.getByRole('button', { name: 'Switch to Acme Labs' }).click()

        await page.waitForURL(/\/acme\/dashboard$/)
        await expect(page.getByTestId('server-card').getByRole('link', { name: 'acme-web', exact: true })).toBeVisible()
        await expect(page.getByTestId('server-card').getByRole('link', { name: 'forge-01', exact: true })).toHaveCount(0)
    })

    test('a page opened without a team address follows the last team used', async ({ page }) => {
        await page.goto('/acme/dashboard')
        await expect(page.getByTestId('server-card').getByRole('link', { name: 'acme-web', exact: true })).toBeVisible()

        await page.goto('/dashboard')

        await expect(page.getByTestId('server-card').getByRole('link', { name: 'acme-web', exact: true })).toBeVisible()
    })
})

test.describe('team addresses', () => {
    test('every link inside a team address keeps it', async ({ page }, testInfo) => {
        await page.goto('/monoven/dashboard')

        await nav(page).getByRole('link', { name: isMobile(testInfo) ? 'Deploys' : 'Deployments', exact: true }).click()
        await page.waitForURL(/\/monoven\/deployments$/)
        await expect(nav(page).getByRole('link', { name: isMobile(testInfo) ? 'Deploys' : 'Deployments', exact: true })).toHaveAttribute('aria-current', 'page')

        await page.getByTestId('deployment-row').filter({ hasText: 'ledger' }).first().click()
        await page.waitForURL(/\/monoven\/servers\/[A-Za-z0-9]+\/applications\/[A-Za-z0-9]+\/deployments\/[A-Za-z0-9]+/)

        await page.getByTestId('back-link').click()
        await page.waitForURL(/\/monoven\/deployments$/)
    })

    test('settings tabs stay inside the team address', async ({ page }) => {
        await page.goto('/monoven/settings')

        await tabs(page, 'Settings').getByRole('link', { name: 'Team', exact: true }).click()

        await page.waitForURL(/\/monoven\/settings\/team$/)
        await expect(tabs(page, 'Settings').getByRole('link', { name: 'Team', exact: true })).toHaveAttribute('aria-current', 'page')
        await expect(page.getByTestId('team-address-card')).toContainText('/monoven')
    })

    test('a team address that does not exist is not found', async ({ page }) => {
        const response = await page.goto('/no-such-team/dashboard')

        expect(response?.status()).toBe(404)
    })

    test('an owner renames the team address and the old one stops working', async ({ page }, testInfo) => {
        const slug = own(testInfo, 'lab')
        const renamed = `${slug}-moved`

        await page.goto(`/${slug}/settings/team`)
        await page.getByTestId('team-address-card').getByRole('button', { name: 'Change' }).click()
        await dialog(page).getByLabel('Address').fill(renamed)
        await dialog(page).getByRole('button', { name: 'Save' }).click()

        await page.waitForURL(new RegExp(`/${renamed}/settings/team$`))
        await expect(page.getByTestId('team-address-card')).toContainText(`/${renamed}`)

        expect((await page.goto(`/${slug}/dashboard`))?.status()).toBe(404)

        await page.goto(`/${renamed}/settings/team`)
        await page.getByTestId('team-address-card').getByRole('button', { name: 'Change' }).click()
        await dialog(page).getByLabel('Address').fill(slug)
        await dialog(page).getByRole('button', { name: 'Save' }).click()

        await page.waitForURL(new RegExp(`/${slug}/settings/team$`))
    })

    test('a reserved team address is refused with a reason', async ({ page }, testInfo) => {
        await page.goto(`/${own(testInfo, 'lab')}/settings/team`)
        await page.getByTestId('team-address-card').getByRole('button', { name: 'Change' }).click()
        await dialog(page).getByLabel('Address').fill('settings')
        await dialog(page).getByRole('button', { name: 'Save' }).click()

        await expect(dialog(page)).toContainText('That address is reserved. Try another one.')
    })
})

test.describe('custom domains', () => {
    test('an owner adds a domain, sees the records to create and removes it again', async ({ page }, testInfo) => {
        const slug = own(testInfo, 'lab')
        const hostname = `panel.${slug}.e2e.test`

        await page.goto(`/${slug}/settings/team`)

        const card = page.getByTestId('custom-domain-card')

        await card.getByRole('button', { name: 'Add a domain' }).click()
        await dialog(page).getByLabel('Domain').fill(hostname)
        await dialog(page).getByRole('button', { name: 'Add domain' }).click()

        await expect(toast(page)).toContainText('Saved.')
        await expect(card).toContainText('Waiting for DNS')
        await expect(card.getByTestId('dns-records')).toContainText(`_coresmith-challenge.${hostname}`)
        await expect(card.getByTestId('dns-records')).toContainText('coresmith-verification=')

        await card.getByRole('button', { name: 'Check DNS' }).click()
        await expect(toast(page)).toContainText('The TXT record is not visible yet.')

        await card.getByRole('button', { name: 'Remove' }).click()
        await dialog(page).getByRole('button', { name: 'Remove domain' }).click()

        await expect(toast(page)).toContainText('Removed.')
        await expect(card.getByRole('button', { name: 'Add a domain' })).toBeVisible()
    })

    test('a domain that is not a domain is refused in the dialog', async ({ page }, testInfo) => {
        await page.goto(`/${own(testInfo, 'lab')}/settings/team`)

        await page.getByTestId('custom-domain-card').getByRole('button', { name: 'Add a domain' }).click()
        await dialog(page).getByLabel('Domain').fill('not a domain')
        await dialog(page).getByRole('button', { name: 'Add domain' }).click()

        await expect(dialog(page)).toContainText("That doesn't look like a domain. Try panel.example.com.")
    })
})

test.describe('a team on its own domain', () => {
    const origin = process.env.APP_URL ?? `http://127.0.0.1:${process.env.E2E_PORT ?? 8123}`

    test.use({ proxy: { server: origin } })

    test('a verified domain opens its team, keeps links on it and switching leaves for the panel address', async ({ page, context }, testInfo) => {
        const cookies = await context.cookies(origin)

        await context.addCookies(cookies.map(({ name, value }) => ({ name, value, domain: 'panel.northwind.test', path: '/' })))

        await page.goto('http://panel.northwind.test/dashboard')

        await expect(page.getByTestId('server-card').getByRole('link', { name: 'northwind-web', exact: true })).toBeVisible()
        await expect(page.getByTestId('server-card').getByRole('link', { name: 'forge-01', exact: true })).toHaveCount(0)

        await nav(page).getByRole('link', { name: isMobile(testInfo) ? 'Deploys' : 'Deployments', exact: true }).click()
        await page.waitForURL('http://panel.northwind.test/deployments')

        expect((await page.goto('http://panel.northwind.test/monoven/dashboard'))?.status()).toBe(404)

        await page.goto('http://panel.northwind.test/dashboard')
        await page.getByRole('button', { name: 'Account' }).click()
        await dialog(page).getByTestId('team-switcher').getByRole('button', { name: 'Switch to Monoven Ltd' }).click()

        await page.waitForURL(`${origin}/monoven/dashboard`)
        await expect(page.getByTestId('server-card').getByRole('link', { name: 'forge-01', exact: true })).toBeVisible()
    })

    test('a domain still waiting for DNS does not open its team', async ({ page, context }) => {
        const cookies = await context.cookies(origin)

        await context.addCookies(cookies.map(({ name, value }) => ({ name, value, domain: 'panel.acme.test', path: '/' })))

        await page.goto('http://panel.acme.test/dashboard')

        await expect(page.getByTestId('server-card').getByRole('link', { name: 'forge-01', exact: true })).toBeVisible()
        await expect(page.getByTestId('server-card').getByRole('link', { name: 'acme-web', exact: true })).toHaveCount(0)
    })
})

asMember.describe('team addresses as a member', () => {
    asMember('a deployer sees the address but cannot change it or add a domain', async ({ page }) => {
        await page.goto('/monoven/settings/team')

        await expect(page.getByTestId('team-address-card')).toContainText('/monoven')
        await expect(page.getByTestId('team-address-card').getByRole('button', { name: 'Change' })).toHaveCount(0)
        await expect(page.getByTestId('custom-domain-card')).toContainText('An owner or admin can add one.')
    })
})
