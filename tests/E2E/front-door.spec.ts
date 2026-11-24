import type { Page } from '@playwright/test'
import { blockingViolations, dialog, expect, gotoServerTab, test, useTheme, withProps } from './support/fixtures'

test.use({ reducedMotion: 'reduce' })

const SERVER = 'forge-01'

const OFF = {
    state: 'off',
    doors: [
        { key: '01doorweb1', name: 'web-1', ipv4: '203.0.113.9' },
        { key: '01doorweb2', name: 'web-2', ipv4: '203.0.113.10' }
    ],
    door: null,
    hosts: [],
    skipped: [],
    failing: [],
    records: [],
    error: null,
    checked: null,
    tunnel_on: false,
    automatic_dns: true,
    can_manage: true
}

const OPEN = {
    ...OFF,
    state: 'open',
    doors: [],
    door: { name: 'web-1', url: '/servers/web-1' },
    hosts: ['beelink.example.com', 'notes.example.com'],
    failing: [
        { host: 'shop.example.net', reason: "shop.example.net doesn't point at web-1 yet." },
        {
            host: 'books.example.net',
            reason: "books.example.net didn't load through web-1. Check that its app is deployed on forge-01."
        }
    ],
    records: [{ type: 'A', name: 'shop.example.net', value: '203.0.113.9' }],
    checked: '4 minutes ago',
    automatic_dns: false
}

async function serverShowing(page: Page, frontDoor: Record<string, unknown>) {
    await withProps(page, /\/servers\/[\w.-]+\/network$/, (props) => {
        props.frontDoor = frontDoor
    })
    await gotoServerTab(page, SERVER, 'Network')

    return page.getByTestId('front-door-card')
}

test.describe('IPv4 through another server', () => {
    test("a server the internet cannot reach on IPv4 offers the team's open servers as a front door, in light", async ({
        page
    }) => {
        await useTheme(page, 'light')
        const card = await serverShowing(page, OFF)

        await expect(card.getByRole('heading', { level: 2, name: 'IPv4 through another server' })).toBeVisible()
        await expect(card).toContainText(
            'One of your other servers can take visitors in on its IPv4 address and pass them here over WireGuard. Uploads and slow pages are limited only by the two servers.'
        )
        expect(await blockingViolations(page)).toEqual([])

        await card.getByRole('button', { name: 'Turn on' }).click()

        await expect(
            dialog(page).getByRole('heading', { name: 'Take IPv4 visitors through another server' })
        ).toBeVisible()
        await expect(dialog(page).getByLabel('Server').locator('option')).toHaveText([
            'web-1 (203.0.113.9)',
            'web-2 (203.0.113.10)'
        ])
        await expect(dialog(page)).toContainText('open UDP 51871 there too')
        expect(await blockingViolations(page)).toEqual([])
    })

    test('with it on, the card counts the domains that reach the server, and lists the rest with the records to set, in dark', async ({
        page
    }) => {
        await useTheme(page, 'dark')
        const card = await serverShowing(page, OPEN)

        await expect(card.getByText('On', { exact: true })).toBeVisible()
        await expect(card.getByTestId('front-door-summary')).toHaveText(
            '2 domains reach this server through web-1, over IPv4 and IPv6.'
        )
        await expect(card.getByTestId('front-door-summary').getByRole('link', { name: 'web-1' })).toHaveAttribute(
            'href',
            '/servers/web-1'
        )
        await expect(card.getByTestId('front-door-failing')).toContainText(
            "These 2 domains don't reach this server through web-1 yet:"
        )
        await expect(card.getByTestId('front-door-failing').getByRole('listitem')).toHaveCount(2)
        await expect(card.getByTestId('front-door-records')).toContainText('shop.example.net')
        await expect(card.getByRole('button', { name: 'Check again' })).toBeVisible()
        expect(await blockingViolations(page)).toEqual([])

        await card.getByTestId('front-door-turn-off').click()

        await expect(dialog(page).getByRole('heading', { name: 'Turn off IPv4 through web-1?' })).toBeVisible()
        await expect(dialog(page)).toContainText('Point the domains back at forge-01 yourself, or they stop loading.')
    })

    test('while it is being set up or turned off, the card says what is happening and offers no buttons', async ({
        page
    }) => {
        const card = await serverShowing(page, { ...OPEN, state: 'opening', failing: [], records: [] })

        await expect(card.getByRole('status')).toContainText('over WireGuard. This takes a minute or two.')
        await expect(card.getByRole('button')).toHaveCount(0)
    })

    test('a setup that did not finish says why and offers to try again', async ({ page }) => {
        const card = await serverShowing(page, {
            ...OPEN,
            state: 'failed',
            hosts: [],
            failing: [],
            records: [],
            error: "web-1 didn't answer over WireGuard. Allow UDP 51871 into web-1 (203.0.113.9) in your provider's firewall, then try again."
        })

        await expect(card.getByTestId('front-door-error')).toContainText('Allow UDP 51871 into web-1')
        await expect(card.getByRole('button', { name: 'Try again' })).toBeVisible()
        await expect(card.getByRole('button', { name: 'Turn off' })).toBeVisible()
    })

    test('with the Cloudflare tunnel on, the card says to turn that off first and offers no switch', async ({
        page
    }) => {
        const card = await serverShowing(page, { ...OFF, tunnel_on: true })

        await expect(card.getByTestId('front-door-tunnel-on')).toContainText('turn that off first')
        await expect(card.getByRole('button', { name: 'Turn on' })).toHaveCount(0)
    })

    test('with no other open server, the card says so and offers to add one', async ({ page }) => {
        const card = await serverShowing(page, { ...OFF, doors: [] })

        await expect(card.getByTestId('front-door-no-doors')).toBeVisible()
        await expect(card.getByRole('link', { name: 'Add a server' })).toBeVisible()
    })

    test('people who are not the team owner see the card but no switch', async ({ page }) => {
        const card = await serverShowing(page, { ...OFF, doors: [], can_manage: false })

        await expect(card).toContainText('A team owner can turn this on.')
        await expect(card.getByRole('button')).toHaveCount(0)
    })
})
