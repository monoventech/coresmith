import type { Locator, Page } from '@playwright/test'
import {
    asMember,
    blockingViolations,
    captureClipboard,
    clipboardText,
    dialog,
    expect,
    gotoSettings,
    test,
    useTheme,
    withProps
} from './support/fixtures'

const MOVED = {
    host: 'coresmith.example.com',
    url: 'https://coresmith.example.com',
    certificate: 'public',
    change: { state: 'done', from: '127.0.0.1', to: 'coresmith.example.com', error: null },
    follow: { github: '/settings/git', sso: 'https://coresmith.example.com/sso/callback' }
}

const FAILED = {
    change: {
        state: 'failed',
        from: '127.0.0.1',
        to: 'coresmith.example.com',
        error: 'No certificate for coresmith.example.com after 3 minutes. Check that it points at this server and that ports 80 and 443 reach this server from the internet, then try again.'
    }
}

const IPV6_ONLY_NOTE =
    "coresmith.example.com has only an IPv6 address, so visitors on networks without IPv6 can't open it. Nothing on the internet reaches this server's IPv4 address, 203.0.113.7, on ports 80 and 443, so if you can open or forward those ports, add an A record for coresmith.example.com pointing at 203.0.113.7. If your internet provider shares that address with other customers, put coresmith.example.com behind a proxy that answers on IPv4, such as Cloudflare's."

const IPV6_ONLY = {
    host: 'coresmith.example.com',
    url: 'https://coresmith.example.com',
    certificate: 'public',
    ipv6_only: true,
    reach: {
        host: 'coresmith.example.com',
        ready: true,
        problems: [],
        notes: [IPV6_ONLY_NOTE],
        records: [{ type: 'A', name: 'coresmith.example.com', value: '203.0.113.7' }],
        checked_at: '2026-11-13T05:50:00Z'
    }
}

const CHECK_WITH_RECORD = {
    host: 'shop.example.com',
    ok: false,
    problems: [
        "shop.example.com doesn't point anywhere yet. Add an A record for shop.example.com pointing at 203.0.113.7, then check again."
    ],
    notes: [],
    records: [{ type: 'A', name: 'shop.example.com', value: '203.0.113.7' }],
    checked_at: '2026-11-13T05:50:00Z'
}

function card(page: Page): Locator {
    return page.getByTestId('address-card')
}

async function settingsShowing(page: Page, address: Record<string, unknown>) {
    await withProps(page, /\/settings$/, (props) => {
        props.address = { ...(props.address as Record<string, unknown>), ...address }
    })
    await gotoSettings(page, 'General')
}

test.describe("Coresmith's address", () => {
    test('the card shows where Coresmith answers and how browsers treat its certificate', async ({ page, baseURL }) => {
        await gotoSettings(page, 'General')

        await expect(card(page).getByRole('link', { name: '127.0.0.1' })).toHaveAttribute(
            'href',
            new URL(String(baseURL)).origin
        )
        await expect(card(page)).toContainText('Browsers warn')
        await expect(card(page)).toContainText('browsers warn until Coresmith has a domain or a Tailscale name')
    })

    test('a domain with only an IPv6 address says who cannot open it and what would fix that', async ({ page }) => {
        await settingsShowing(page, {
            host: 'coresmith.example.com',
            url: 'https://coresmith.example.com',
            certificate: 'public',
            ipv6_only: true
        })

        await expect(card(page).getByTestId('address-ipv6-only')).toHaveText(
            "coresmith.example.com has only an IPv6 address, so people on networks without IPv6 can't open Coresmith. If the internet reaches this server's IPv4 address on ports 80 and 443, add an A record for it. If your internet provider shares that address with other customers, put coresmith.example.com behind a proxy that answers on IPv4, such as Cloudflare's."
        )
        await expect(card(page).getByTestId('address-ipv6-records')).toHaveCount(0)
    })

    test("the daily reading of an IPv6-only domain names this server's IPv4 address, ready to copy", async ({ page }) => {
        await captureClipboard(page)
        await settingsShowing(page, IPV6_ONLY)

        await expect(card(page).getByTestId('address-ipv6-only')).toHaveText(IPV6_ONLY_NOTE)

        const records = card(page).getByTestId('address-ipv6-records')

        await expect(records).toContainText('coresmith.example.com')
        await records.getByRole('button', { name: 'Copy the A value 203.0.113.7' }).click()

        await expect(records.getByRole('button', { name: 'Copied the A value 203.0.113.7' })).toBeVisible()
        expect(await clipboardText(page)).toBe('203.0.113.7')
    })

    for (const theme of ['light', 'dark'] as const) {
        test(`the IPv6-only note and its record are accessible in ${theme}`, async ({ page }) => {
            await useTheme(page, theme)
            await settingsShowing(page, IPV6_ONLY)
            await expect(card(page).getByTestId('address-ipv6-records')).toBeVisible()

            expect(await blockingViolations(page)).toEqual([])
        })
    }

    test('a check that asks for a record shows it ready to copy', async ({ page }) => {
        await captureClipboard(page)
        await withProps(page, /\/settings\/address\/check$/, (props) => {
            props.address = { ...(props.address as Record<string, unknown>), check: CHECK_WITH_RECORD }
        })
        await gotoSettings(page, 'General')
        await card(page).getByRole('button', { name: 'Change' }).click()
        await dialog(page).getByLabel('Domain').fill('beelink')
        await dialog(page).getByRole('button', { name: 'Check', exact: true }).click()

        const records = dialog(page).getByTestId('address-records')

        await expect(page.getByTestId('address-check')).toContainText(CHECK_WITH_RECORD.problems[0])
        await expect(records).toContainText('shop.example.com')
        await records.getByRole('button', { name: 'Copy the A value 203.0.113.7' }).click()

        expect(await clipboardText(page)).toBe('203.0.113.7')
    })

    test("a name that isn't a domain is checked, and the check says what's wrong without allowing a switch", async ({
        page
    }) => {
        await gotoSettings(page, 'General')
        await card(page).getByRole('button', { name: 'Change' }).click()
        await expect(dialog(page).getByRole('button', { name: 'Switch address' })).toBeDisabled()
        await dialog(page).getByLabel('Domain').fill('beelink')
        await dialog(page).getByRole('button', { name: 'Check', exact: true }).click()

        await expect(page.getByTestId('address-check')).toHaveText(
            "That doesn't look like a domain. Try coresmith.example.com."
        )
        await expect(dialog(page).getByRole('button', { name: 'Check again' })).toBeVisible()
        await expect(dialog(page).getByRole('button', { name: 'Switch address' })).toBeDisabled()
    })

    test('a check that passes allows the switch, and changing the name asks for a new check', async ({ page }) => {
        await gotoSettings(page, 'General')
        await card(page).getByRole('button', { name: 'Change' }).click()
        await dialog(page).getByLabel('Domain').fill('[::1]')
        await dialog(page).getByRole('button', { name: 'Check', exact: true }).click()

        await expect(page.getByTestId('address-check')).toHaveText('Coresmith can move to ::1.')
        await expect(dialog(page).getByRole('button', { name: 'Switch address' })).toBeEnabled()

        await dialog(page).getByLabel('Domain').fill('[::1]x')

        await expect(page.getByTestId('address-check')).toHaveCount(0)
        await expect(dialog(page).getByRole('button', { name: 'Switch address' })).toBeDisabled()
        await expect(dialog(page).getByRole('button', { name: 'Check', exact: true })).toBeVisible()
    })

    for (const theme of ['light', 'dark'] as const) {
        test(`the check's findings are accessible in ${theme}`, async ({ page }) => {
            await useTheme(page, theme)
            await gotoSettings(page, 'General')
            await card(page).getByRole('button', { name: 'Change' }).click()
            await dialog(page).getByLabel('Domain').fill('beelink')
            await dialog(page).getByRole('button', { name: 'Check', exact: true }).click()
            await expect(page.getByTestId('address-check')).toBeVisible()

            expect(await blockingViolations(page)).toEqual([])
        })
    }

    test('a switch under way says what it waits for, and offers no second one', async ({ page }) => {
        await settingsShowing(page, {
            change: { state: 'running', from: '127.0.0.1', to: 'coresmith.example.com', error: null }
        })

        await expect(card(page)).toContainText('Switching')
        await expect(card(page).getByRole('status')).toHaveText(
            'Switching to coresmith.example.com. 127.0.0.1 keeps working until the certificate is ready.'
        )
        await expect(card(page).getByRole('button', { name: 'Change' })).toHaveCount(0)
    })

    test('a failed switch says why and tries the same name again', async ({ page }) => {
        await settingsShowing(page, FAILED)

        await expect(page.getByTestId('address-error')).toHaveText(FAILED.change.error)
        await expect(card(page).getByRole('button', { name: 'Change' })).toHaveCount(0)
        await card(page).getByRole('button', { name: 'Try again' }).click()

        await expect(dialog(page).getByLabel('Domain')).toHaveValue('coresmith.example.com')
    })

    test('after a move the card says what else needs the new address', async ({ page }) => {
        await settingsShowing(page, MOVED)

        await expect(card(page)).toContainText('Trusted certificate')
        await expect(card(page).getByRole('status')).toHaveText('Moved from 127.0.0.1, which now sends people here.')
        await expect(card(page).getByRole('link', { name: 'Open the new address' })).toHaveAttribute(
            'href',
            'https://coresmith.example.com'
        )
        await expect(page.getByTestId('address-follow')).toContainText('GitHub still sends events to the old address.')
        await expect(page.getByTestId('address-follow')).toContainText(
            'Add https://coresmith.example.com/sso/callback as a redirect URI at your identity provider'
        )
    })

    test('an install from before this setting says to run the installer instead', async ({ page }) => {
        await settingsShowing(page, { managed: false })

        await expect(card(page).getByRole('button', { name: 'Change' })).toHaveCount(0)
        await expect(card(page).getByRole('region', { name: 'Command that changes the address' })).toHaveText(
            'curl -fsSL https://get.coresmith.io/install | sudo bash -s -- --domain coresmith.example.com'
        )
    })

    for (const theme of ['light', 'dark'] as const) {
        test(`a failed and a finished switch have no serious or critical violations in ${theme}`, async ({ page }) => {
            await useTheme(page, theme)
            await settingsShowing(page, FAILED)
            expect(await blockingViolations(page), `failed switch in ${theme}`).toEqual([])

            await page.unrouteAll({ behavior: 'ignoreErrors' })
            await settingsShowing(page, MOVED)
            expect(await blockingViolations(page), `finished switch in ${theme}`).toEqual([])
        })
    }
})

asMember("a member sees Coresmith's address but cannot change it", async ({ page }) => {
    await gotoSettings(page, 'General')

    await expect(card(page).getByRole('link', { name: '127.0.0.1' })).toBeVisible()
    await expect(card(page).getByRole('button', { name: 'Change' })).toHaveCount(0)
    await expect(card(page)).not.toContainText('run the installer again')
})

test.describe('Cloudflare DNS for the address', () => {
    test("without Cloudflare the card offers to connect it, and a token Cloudflare doesn't accept is refused next to the field", async ({
        page
    }) => {
        await gotoSettings(page, 'General')
        await expect(page.getByTestId('address-dns')).toContainText(
            "Coresmith can keep its domain's DNS records pointed here, with a Cloudflare API token."
        )
        await page.getByTestId('address-dns').getByRole('button', { name: 'Connect Cloudflare' }).click()
        await dialog(page).getByLabel('API token').fill('not-a-token')
        await dialog(page).getByRole('button', { name: 'Connect', exact: true }).click()

        await expect(dialog(page)).toContainText(
            "Cloudflare doesn't accept that token. Create one in Cloudflare under My Profile, API Tokens, from the Edit zone DNS template, then paste it here."
        )
        await expect(dialog(page).getByLabel('API token')).toHaveAttribute('aria-invalid', 'true')
    })

    test('a connected Cloudflare says what it keeps pointed here, or what it refused', async ({ page }) => {
        await settingsShowing(page, {
            cloudflare: { connected: true, host: 'coresmith.example.com', error: null, synced: '3 minutes ago' }
        })

        await expect(page.getByTestId('address-dns')).toContainText(
            'Keeps coresmith.example.com pointed at this server. Checked 3 minutes ago.'
        )
        await expect(page.getByTestId('address-dns').getByRole('button', { name: 'Disconnect' })).toBeVisible()

        await settingsShowing(page, {
            cloudflare: {
                connected: true,
                host: 'coresmith.example.com',
                error: 'Cloudflare refused the change: Authentication error.',
                synced: '3 minutes ago'
            }
        })

        await expect(page.getByTestId('address-dns')).toContainText(
            'Cloudflare refused the change: Authentication error.'
        )
    })

    test('with Cloudflare connected, a check that fails offers to point the domain here', async ({ page }) => {
        const connected = { cloudflare: { connected: true, host: null, error: null, synced: null } }

        await withProps(page, /\/settings\/address\/check$/, (props) => {
            props.address = { ...(props.address as Record<string, unknown>), ...connected }
        })
        await settingsShowing(page, connected)
        await card(page).getByRole('button', { name: 'Change' }).click()
        await dialog(page).getByLabel('Domain').fill('coresmith.example.com')
        await dialog(page).getByRole('button', { name: 'Check', exact: true }).click()

        await expect(dialog(page).getByRole('button', { name: 'Point it here with Cloudflare' })).toBeVisible()
    })

    for (const theme of ['light', 'dark'] as const) {
        test(`the Cloudflare section and its dialog are accessible in ${theme}`, async ({ page }) => {
            await useTheme(page, theme)
            await gotoSettings(page, 'General')
            await page.getByTestId('address-dns').getByRole('button', { name: 'Connect Cloudflare' }).click()
            await expect(dialog(page).getByLabel('API token')).toBeVisible()

            expect(await blockingViolations(page)).toEqual([])
        })
    }
})
