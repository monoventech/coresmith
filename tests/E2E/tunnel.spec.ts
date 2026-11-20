import type { Page } from '@playwright/test'
import { blockingViolations, dialog, expect, gotoServer, test, useTheme, withProps } from './support/fixtures'

test.use({ reducedMotion: 'reduce' })

const SERVER = 'forge-01'

const CLOSED = {
    state: 'off',
    ipv4: '151.226.169.196',
    hosts: [],
    skipped: [],
    diverted: [],
    error: null,
    checked: null,
    cloudflare: false,
    front_door: null,
    can_manage: true
}

const OPEN = {
    ...CLOSED,
    state: 'open',
    hosts: ['beelink.example.com', 'notes.example.com', 'www.notes.example.com'],
    skipped: [
        {
            host: 'shop.example.net',
            reason: "shop.example.net points at 198.51.100.9, which isn't this server, so Coresmith left it alone."
        }
    ],
    checked: '4 minutes ago',
    cloudflare: true
}

async function serverShowing(page: Page, tunnel: Record<string, unknown>) {
    await withProps(page, /\/servers\/[\w.-]+$/, (props) => {
        props.tunnel = tunnel
    })
    await gotoServer(page, SERVER)

    return page.getByTestId('tunnel-card')
}

test.describe('IPv4 through Cloudflare', () => {
    test('a server the internet cannot reach on IPv4 says why and offers the tunnel, in light', async ({ page }) => {
        await useTheme(page, 'light')
        const card = await serverShowing(page, CLOSED)

        await expect(card.getByRole('heading', { level: 2, name: 'IPv4 through Cloudflare' })).toBeVisible()
        await expect(card.getByText('IPv4 closed', { exact: true })).toBeVisible()
        await expect(card).toContainText(
            "The internet can't reach 151.226.169.196 on ports 80 and 443, so people without IPv6 can't open the apps on this server."
        )
        expect(await blockingViolations(page)).toEqual([])

        await card.getByRole('button', { name: 'Turn on' }).click()

        await expect(dialog(page).getByRole('heading', { name: 'Turn on IPv4 through Cloudflare' })).toBeVisible()
        await expect(dialog(page).getByLabel('Cloudflare API token')).toBeVisible()
        await expect(dialog(page)).toContainText('Account, Cloudflare Tunnel, Edit and Zone, DNS, Edit')
        expect(await blockingViolations(page)).toEqual([])

        await dialog(page).getByRole('button', { name: 'Cancel' }).click()
        await expect(dialog(page)).toHaveCount(0)
    })

    test("the token field links to Cloudflare's account token form with both permissions filled in", async ({
        page
    }) => {
        const card = await serverShowing(page, CLOSED)

        await card.getByRole('button', { name: 'Turn on' }).click()

        const link = dialog(page).getByTestId('tunnel-token-link')

        await expect(link).toHaveAccessibleName('Create one in Cloudflare (opens in a new tab)')
        await expect(link).toHaveAttribute('target', '_blank')

        const href = new URL(String(await link.getAttribute('href')))

        expect(href.origin).toBe('https://dash.cloudflare.com')
        expect(href.searchParams.get('to')).toBe('/:account/api-tokens')
        expect(href.searchParams.get('name')).toBe('Coresmith')
        expect(JSON.parse(String(href.searchParams.get('permissionGroupKeys')))).toEqual([
            { key: 'c07321b023e944ff818fec44d8203567', type: 'edit' },
            { key: 'dns', type: 'edit' }
        ])
    })

    test('with the tunnel on, the card counts the domains, lists them on request and names those left alone, in dark', async ({
        page
    }) => {
        await useTheme(page, 'dark')
        const card = await serverShowing(page, OPEN)

        await expect(card.getByText('On', { exact: true })).toBeVisible()
        await expect(card.getByTestId('tunnel-summary')).toHaveText(
            '3 domains reach this server through Cloudflare, over IPv4 and IPv6.'
        )
        await expect(card.getByTestId('tunnel-hosts')).toBeHidden()

        await card.getByRole('button', { name: 'Show the domains' }).click()

        await expect(card.getByTestId('tunnel-hosts').getByRole('listitem')).toHaveText([
            'beelink.example.com',
            'notes.example.com',
            'www.notes.example.com'
        ])
        await expect(card.getByTestId('tunnel-skipped')).toContainText(
            "shop.example.net points at 198.51.100.9, which isn't this server, so Coresmith left it alone."
        )
        await expect(card).toContainText('Checked 4 minutes ago.')
        expect(await blockingViolations(page)).toEqual([])
    })

    test('domains Cloudflare redirects elsewhere are named with where they go, and left out of the count', async ({
        page
    }) => {
        const card = await serverShowing(page, {
            ...OPEN,
            diverted: [
                { host: 'notes.example.com', to: 'cloudflare.vc' },
                { host: 'www.notes.example.com', to: 'cloudflare.vc' }
            ]
        })

        await expect(card.getByTestId('tunnel-summary')).toHaveText(
            '1 domain reaches this server through Cloudflare, over IPv4 and IPv6.'
        )

        const diverted = card.getByTestId('tunnel-diverted')

        await expect(diverted).toContainText(
            'Cloudflare redirects these 2 domains to another site, so visitors never reach this server:'
        )
        await expect(diverted.getByRole('listitem')).toHaveText([
            'notes.example.com goes to cloudflare.vc',
            'www.notes.example.com goes to cloudflare.vc'
        ])
        await expect(diverted).toContainText(
            "If that isn't on purpose, delete the redirect in Cloudflare. Look in Redirect Rules, Page Rules and Bulk Redirects. Then choose Check again."
        )
        expect(await blockingViolations(page)).toEqual([])
    })

    test('turning off asks first and says what happens to the domains', async ({ page }) => {
        const card = await serverShowing(page, OPEN)

        await card.getByRole('button', { name: 'Turn off' }).click()

        await expect(dialog(page).getByRole('heading', { name: 'Turn off the tunnel?' })).toBeVisible()
        await expect(dialog(page)).toContainText(
            `Coresmith points 3 domains back at ${SERVER}'s own addresses, then removes the tunnel and its connector. People without IPv6 can't reach them after that.`
        )
        expect(await blockingViolations(page)).toEqual([])

        await dialog(page).getByRole('button', { name: 'Cancel' }).click()
        await expect(dialog(page)).toHaveCount(0)
    })

    test('a tunnel that did not finish says why and offers to try again', async ({ page }) => {
        const card = await serverShowing(page, {
            ...CLOSED,
            state: 'failed',
            cloudflare: true,
            error: "Cloudflare's connector is running on forge-01, but Cloudflare hasn't seen it connect after a minute. Check that forge-01 can reach the internet, then try again."
        })

        await expect(card.getByText("Didn't finish", { exact: true })).toBeVisible()
        await expect(card.getByTestId('tunnel-error')).toContainText("Cloudflare hasn't seen it connect after a minute")
        await expect(card.getByRole('button', { name: 'Try again' })).toBeVisible()
        await expect(card.getByRole('button', { name: 'Turn off' })).toBeVisible()
    })

    test('while the tunnel is being set up, the card says what is happening and offers no buttons', async ({
        page
    }) => {
        const card = await serverShowing(page, { ...CLOSED, state: 'opening', cloudflare: true })

        await expect(card.getByRole('status')).toContainText(`Installing Cloudflare's connector on ${SERVER}`)
        await expect(card.getByRole('button')).toHaveCount(0)
    })

    test('people who do not manage Coresmith see the problem but no switch', async ({ page }) => {
        const card = await serverShowing(page, { ...CLOSED, can_manage: false })

        await expect(card).toContainText('Someone who manages Coresmith can turn this on.')
        await expect(card.getByRole('button', { name: 'Turn on' })).toHaveCount(0)
    })

    test('while the server takes visitors through another one, the card names it and offers no switch', async ({
        page
    }) => {
        const card = await serverShowing(page, { ...CLOSED, front_door: 'web-1' })

        await expect(card.getByTestId('tunnel-door-on')).toHaveText(
            'IPv4 through web-1 is on for this server. To bring visitors in through Cloudflare instead, turn that off first.'
        )
        await expect(card.getByText('IPv4 closed', { exact: true })).toHaveCount(0)
        await expect(card.getByRole('button')).toHaveCount(0)
    })
})
