import type { Locator, Page } from '@playwright/test'
import { asMember, blockingViolations, dialog, expect, gotoSettings, test, useTheme } from './support/fixtures'

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

function card(page: Page): Locator {
    return page.getByTestId('address-card')
}

async function settingsShowing(page: Page, address: Record<string, unknown>) {
    await page.route(/\/settings$/, async (route) => {
        if (route.request().headers()['x-inertia'] === undefined) {
            await route.continue()

            return
        }

        const response = await route.fetch()
        const body = await response.json()

        body.props.address = { ...body.props.address, ...address }
        await route.fulfill({ response, json: body })
    })
    await gotoSettings(page, 'General')
}

test.describe("Coresmith's address", () => {
    test('the card shows where Coresmith answers and how browsers treat its certificate', async ({ page }) => {
        await gotoSettings(page, 'General')

        await expect(card(page).getByRole('link', { name: '127.0.0.1' })).toHaveAttribute(
            'href',
            'http://127.0.0.1:8123'
        )
        await expect(card(page)).toContainText('Browsers warn')
        await expect(card(page)).toContainText('browsers warn until Coresmith has a domain or a Tailscale name')
    })

    test('an address that is not a domain is refused next to the field', async ({ page }) => {
        await gotoSettings(page, 'General')
        await card(page).getByRole('button', { name: 'Change' }).click()
        await dialog(page).getByLabel('Domain').fill('beelink')
        await dialog(page).getByRole('button', { name: 'Switch address' }).click()

        await expect(dialog(page)).toContainText("That doesn't look like a domain. Try coresmith.example.com.")
        await expect(dialog(page).getByLabel('Domain')).toHaveAttribute('aria-invalid', 'true')
    })

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
