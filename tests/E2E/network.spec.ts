import type { Page } from '@playwright/test'
import { blockingViolations, expect, gotoServer, gotoServerTab, test, useTheme, withProps } from './support/fixtures'

test.use({ reducedMotion: 'reduce' })

const SERVER = 'forge-01'

const ADDRESSES = {
    rows: [
        {
            label: 'IPv4',
            values: ['151.226.169.196'],
            note: "Visitors can't reach it from the internet, so they come in through Cloudflare.",
            tone: 'success'
        },
        { label: 'IPv6', values: ['2a06:5902:360e:2700::1'], note: null, tone: null },
        { label: 'Private', values: ['192.168.0.248'], note: null, tone: null }
    ],
    checked: 'today 13:25'
}

const NOTICE =
    "Visitors can't reach this server's IPv4 address from the internet, so people without IPv6 can't open the apps here."

async function networkShowing(page: Page) {
    await withProps(page, /\/servers\/[\w.-]+\/network$/, (props) => {
        props.addresses = ADDRESSES
    })
    await gotoServerTab(page, SERVER, 'Network')

    return page.getByTestId('addresses-card')
}

async function appsWithNotice(page: Page) {
    await withProps(page, /\/servers\/[\w.-]+$/, (props) => {
        const server = props.server as { tabs: { network: string } }

        props.network = { text: NOTICE, action: 'Bring IPv4 visitors in', href: server.tabs.network }
    })
    await gotoServer(page, SERVER)

    return page.getByTestId('network-notice')
}

test.describe("a server's Network tab", () => {
    test('it lists the addresses and how IPv4 visitors get in', async ({ page }) => {
        const card = await networkShowing(page)

        await expect(
            page.getByRole('navigation', { name: 'Server sections' }).getByRole('link', { name: 'Network' })
        ).toHaveAttribute('aria-current', 'page')
        await expect(card.getByRole('heading', { level: 2, name: 'Addresses' })).toBeVisible()

        const rows = card.getByTestId('address-row')

        await expect(rows).toHaveCount(3)
        await expect(rows.nth(0)).toContainText('151.226.169.196')
        await expect(rows.nth(0)).toContainText('they come in through Cloudflare')
        await expect(rows.nth(1)).toContainText('2a06:5902:360e:2700::1')
        await expect(rows.nth(2)).toContainText('192.168.0.248')
        await expect(card).toContainText('Checked today 13:25.')
    })

    test('the Apps tab keeps a one-line notice instead of the IPv4 cards, and it opens the Network tab', async ({
        page
    }) => {
        const notice = await appsWithNotice(page)

        await expect(notice).toContainText(NOTICE)
        await expect(page.getByTestId('tunnel-card')).toHaveCount(0)
        await expect(page.getByTestId('front-door-card')).toHaveCount(0)

        await notice.getByRole('link', { name: 'Bring IPv4 visitors in' }).click()

        await page.waitForURL(/\/network$/)
        await expect(page.getByRole('heading', { level: 2, name: 'Addresses' })).toBeVisible()
    })

    for (const theme of ['light', 'dark'] as const) {
        test(`the Network tab and the notice have no serious or critical accessibility violations in ${theme}`, async ({
            page
        }) => {
            await useTheme(page, theme)

            await expect(await appsWithNotice(page)).toBeVisible()
            expect(await blockingViolations(page)).toEqual([])

            await expect(await networkShowing(page)).toBeVisible()
            expect(await blockingViolations(page)).toEqual([])
        })
    }
})
