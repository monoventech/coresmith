import type { Page } from '@playwright/test'
import { DatabaseSync } from 'node:sqlite'
import {
    asMember,
    backToMonoven,
    dialog,
    expect,
    gotoServer,
    noSidewaysScroll,
    own,
    test,
    toast
} from './support/fixtures'

function provider(page: Page) {
    return page.getByLabel('Provider')
}

function finishDestroy(name: string) {
    const database = new DatabaseSync('database/e2e.sqlite')

    try {
        database.exec('PRAGMA busy_timeout = 5000')
        database
            .prepare(
                "UPDATE servers SET deleted_at = datetime('now'), provider_task = json_set(provider_task, '$.state', 'done') WHERE name = ?"
            )
            .run(name)
    } finally {
        database.close()
    }
}

test.describe('provider accounts', () => {
    test.afterEach(async ({ page }) => {
        await backToMonoven(page)
    })

    test('the team lists its provider accounts and the state of each', async ({ page }) => {
        await page.goto('/settings/team')

        const card = page.getByTestId('cloud-providers-card')

        await expect(card).toContainText('3 accounts')
        await expect(card.getByTestId('cloud-account-row').filter({ hasText: 'DigitalOcean' })).toContainText(
            '1 server'
        )
        await expect(card.getByTestId('cloud-account-row').filter({ hasText: 'Linode' })).toContainText('Token refused')
        await expect(
            card
                .getByTestId('cloud-account-row')
                .filter({ hasText: 'Hetzner Cloud' })
                .getByText('Hetzner Cloud', { exact: true })
        ).toHaveCount(1)
        await expect(card).not.toContainText('never-sent')
    })

    test('a provider is only added once its token is checked', async ({ page }) => {
        await page.goto('/settings/team')
        await page.getByTestId('cloud-providers-card').getByRole('button', { name: 'Add a provider' }).click()
        await dialog(page).getByLabel('Provider').selectOption('vultr')
        await dialog(page).getByLabel('API token').fill('   ')
        await dialog(page).getByRole('button', { name: 'Connect Vultr' }).click()

        await expect(dialog(page)).toContainText('Paste the API token from Vultr.')

        await dialog(page).getByLabel('API token').fill('not-a-real-key')
        await dialog(page).getByRole('button', { name: 'Connect Vultr' }).click()

        await expect(dialog(page)).toContainText("Coresmith couldn't reach Vultr")
    })

    test('an account a server still uses cannot be removed', async ({ page }) => {
        await page.goto('/settings/team')
        await page.getByTestId('cloud-providers-card').getByRole('button', { name: 'Remove DigitalOcean' }).click()
        await dialog(page).getByRole('button', { name: 'Remove account' }).click()

        await expect(toast(page)).toContainText('outpost-01 was created with this account.')
    })

    test('an unused account is removed after asking', async ({ page }, testInfo) => {
        await page.goto(`/${own(testInfo, 'lab')}/settings/team`)

        const card = page.getByTestId('cloud-providers-card')

        await expect(card.getByTestId('cloud-account-row')).toContainText('Vultr Spare')
        await card.getByRole('button', { name: 'Remove Spare' }).click()
        await expect(dialog(page)).toContainText('It stays valid at Vultr until you revoke it there.')
        await dialog(page).getByRole('button', { name: 'Remove account' }).click()

        await expect(toast(page)).toContainText('Removed.')
        await expect(card).toContainText('No provider connected yet.')
    })

    test('an account is renamed without its token being checked again', async ({ page }, testInfo) => {
        const name = own(testInfo, 'Sandbox', ' ')
        const renamed = own(testInfo, 'Renamed', ' ')

        await page.goto('/stray/settings/team')
        await page
            .getByTestId('cloud-account-row')
            .filter({ hasText: name })
            .getByRole('button', { name: 'Rename' })
            .click()

        await expect(dialog(page)).toContainText('The name is only for Coresmith, nothing changes at Hetzner Cloud.')

        await dialog(page).getByLabel('Name').fill(renamed)
        await dialog(page).getByRole('button', { name: 'Save' }).click()

        await expect(toast(page)).toContainText('Saved.')
        await expect(page.getByTestId('cloud-account-row').filter({ hasText: renamed })).toHaveCount(1)
        await expect(page.getByTestId('cloud-account-row').filter({ hasText: name })).toHaveCount(0)
    })

    test('a token is replaced only once the new one is checked', async ({ page }) => {
        await page.goto('/settings/team')
        await page
            .getByTestId('cloud-account-row')
            .filter({ hasText: 'Linode' })
            .getByRole('button', { name: 'Replace token' })
            .click()
        await dialog(page).getByLabel('API token').fill('not-a-real-token')
        await dialog(page).getByRole('button', { name: 'Replace token' }).click()

        await expect(dialog(page)).toContainText("Coresmith couldn't reach Linode")
    })
})

asMember('a deployer sees why a destroy failed but cannot detach the server', async ({ page }) => {
    await gotoServer(page, 'destroy-failed')

    const banner = page.getByTestId('cloud-task-banner')

    await expect(banner).toContainText('server is protected')
    await expect(banner.getByRole('button')).toHaveCount(0)
})

asMember('a deployer sees the accounts but cannot change them', async ({ page }) => {
    await page.goto('/settings/team')

    const card = page.getByTestId('cloud-providers-card')

    await expect(card).toContainText('Hetzner Cloud')
    await expect(card.getByRole('button', { name: 'Add a provider' })).toHaveCount(0)
    await expect(card.getByRole('button', { name: 'Replace token' })).toHaveCount(0)
    await expect(card.getByRole('button', { name: 'Rename' })).toHaveCount(0)
    await expect(card.getByRole('button', { name: /^Remove / })).toHaveCount(0)
})

test.describe('provisioning through a provider', () => {
    test("the provision form lists the team's accounts, the providers still to connect and your own vm", async ({
        page
    }) => {
        await page.goto('/servers/create')

        await expect(provider(page).locator('option')).toContainText([
            'DigitalOcean',
            'Hetzner Cloud',
            'Linode, token refused',
            'Vultr, not connected yet',
            'Bring your own VM'
        ])
    })

    test('a connected account offers its regions, sizes and images with prices', async ({ page }) => {
        await page.goto('/servers/create')
        await provider(page).selectOption({ label: 'Hetzner Cloud' })

        await expect(page.getByLabel('Region')).toHaveValue('fsn1')
        await expect(page.getByLabel('Region').locator('option', { hasText: 'Ashburn (ash)' })).toHaveCount(1)
        await expect(page.getByLabel('Size', { exact: true })).toHaveValue('cx22')
        await expect(
            page
                .getByLabel('Size', { exact: true })
                .locator('option', { hasText: /4 GB · 2 vCPU · €3\.79\/mo/ })
                .first()
        ).toBeAttached()
        await expect(page.getByLabel('Image')).toHaveValue('ubuntu-24.04')
        await expect(page.getByRole('main')).toContainText(
            'cx22 · 40 GB disk · €3.79 a month before tax, billed by Hetzner Cloud.'
        )

        await page.getByLabel('Region').selectOption('ash')

        await expect(page.getByLabel('Size', { exact: true }).locator('option')).toHaveCount(1)
        await expect(page.getByLabel('Size', { exact: true })).toHaveValue('cpx21')
        await expect(page.getByLabel('Size', { exact: true }).locator('option')).toContainText('€8.49/mo')
    })

    test('a size with no price in the chosen region shows no price there, never its price elsewhere', async ({
        page
    }) => {
        await page.route(
            (url) => /\/servers\/create$/.test(url.pathname),
            async (route) => {
                if (!route.request().headers()['x-inertia']) {
                    return route.continue()
                }

                const response = await route.fetch()
                const visit = await response.json()

                for (const size of visit.props?.catalog?.sizes ?? []) {
                    if (size.id === 'cx22') {
                        size.price = '€9.99'
                        size.prices = { ...size.prices, fsn1: null }
                    }
                }

                await route.fulfill({ response, json: visit })
            }
        )
        await page.goto('/servers/create')
        await provider(page).selectOption({ label: 'Hetzner Cloud' })

        await expect(page.getByLabel('Size', { exact: true })).toHaveValue('cx22')
        await expect(page.getByLabel('Size', { exact: true }).locator('option[value="cx22"]')).not.toContainText('€')
        await expect(page.getByRole('main')).toContainText('cx22 · 40 GB disk, billed by Hetzner Cloud.')
        await expect(page.getByRole('main')).not.toContainText('€9.99')
    })

    test('a server is created at the provider and the page follows it', async ({ page }) => {
        const name = `e2e-${Date.now().toString(36)}`

        await page.goto('/servers/create')
        await provider(page).selectOption({ label: 'Hetzner Cloud' })
        await page.getByLabel('Server name').fill(name)
        await page.getByLabel('Region').selectOption({ label: 'Falkenstein (fsn1)' })
        await page.getByRole('button', { name: 'Provision server' }).click()

        await expect(page.getByRole('heading', { level: 1, name })).toBeVisible()
        await expect(toast(page)).toContainText('at Hetzner Cloud. This page follows along')
        await expect(page.getByTestId('creation-card')).toContainText('Step 1 of 4')
        await expect(page.getByTestId('creation-card')).toContainText('Create the server')
        await expect(page.getByTestId('provisioning-phases')).toContainText('What happens next')
        await expect(page.getByTestId('provider-line')).toContainText('Hetzner Cloud · cx')
        await expect(page.getByTestId('provider-line')).toContainText('a month before tax')
    })

    test('a provider not connected yet asks for its token first', async ({ page }) => {
        await page.goto('/servers/create')
        await provider(page).selectOption({ label: 'Vultr, not connected yet' })

        await expect(page.getByLabel('API token')).toBeVisible()
        await expect(page.getByLabel('Region')).toHaveCount(0)

        await page.getByLabel('API token').fill('not-a-real-key')
        await page.getByRole('button', { name: 'Connect Vultr' }).click()

        await expect(page.getByRole('alert').filter({ hasText: "Coresmith couldn't reach Vultr" })).toBeVisible()
    })

    test('an account whose token was refused says so instead of loading sizes', async ({ page }) => {
        await page.goto('/servers/create')
        await provider(page).selectOption({ label: 'Linode, token refused' })

        await expect(page.getByRole('main')).toContainText("Linode refused this account's token on")
        await expect(page.getByRole('link', { name: 'Open team settings' })).toBeVisible()
        await expect(page.getByLabel('Region')).toHaveCount(0)
    })

    test('a server being created shows its steps', async ({ page }) => {
        await gotoServer(page, 'creating-server')

        await expect(page.getByTestId('creation-card')).toContainText('Step 2 of 4')
        await expect(page.getByText('Creating', { exact: true }).first()).toBeVisible()
    })

    test('a failed creation says what happened and offers to try again', async ({ page }) => {
        await gotoServer(page, 'failed-create')

        await expect(page.getByTestId('creation-error')).toContainText("couldn't sign in over SSH within 10 minutes")
        await expect(page.getByRole('button', { name: 'Try again' })).toBeVisible()
    })

    test('a creation the provider refused can be removed', async ({ page }) => {
        await gotoServer(page, 'refused-create')
        await page.getByRole('button', { name: 'Remove', exact: true }).click()

        await expect(dialog(page)).toContainText(
            'Nothing was created at Hetzner Cloud, so there is nothing to destroy.'
        )

        await dialog(page).getByRole('button', { name: 'Keep it' }).click()

        await expect(dialog(page)).toHaveCount(0)
    })

    test('detaching a creation the provider refused says nothing was created there', async ({ page }) => {
        await gotoServer(page, 'refused-create')
        await page.getByRole('button', { name: 'Detach', exact: true }).click()

        const detach = page.getByRole('dialog', { name: 'Detach refused-create?' })

        await expect(detach).toContainText(
            'Nothing was created at Hetzner Cloud, so refused-create only leaves Coresmith.'
        )
        await expect(detach).not.toContainText('VM')

        await detach.getByRole('button', { name: 'Keep it' }).click()

        await expect(dialog(page)).toHaveCount(0)
    })

    test('a creation Coresmith could not confirm offers to destroy it, and says to check the provider before detaching', async ({
        page
    }) => {
        await gotoServer(page, 'lost-create')

        const card = page.getByTestId('creation-card')

        await expect(card.getByRole('button', { name: 'Remove', exact: true })).toHaveCount(0)

        for (const opener of [
            card.getByRole('button', { name: 'Destroy it' }),
            page.getByRole('button', { name: 'Destroy', exact: true })
        ]) {
            await opener.click()

            const destroy = page.getByRole('dialog', { name: 'Destroy lost-create?' })

            await expect(destroy).toContainText(
                'Coresmith could not confirm whether Hetzner Cloud created lost-create. If it did, Hetzner Cloud deletes it and its disk, and billing for it stops. Either way, lost-create leaves Coresmith.'
            )

            await destroy.getByRole('button', { name: 'detach it instead' }).click()

            const detach = page.getByRole('dialog', { name: 'Detach lost-create?' })

            await expect(detach).toContainText(
                'Coresmith could not confirm whether Hetzner Cloud created lost-create. Check your Hetzner Cloud account and delete it there if it exists.'
            )

            await detach.getByRole('button', { name: 'Keep it' }).click()

            await expect(dialog(page)).toHaveCount(0)
        }
    })

    test('trying again resumes the creation', async ({ page }, testInfo) => {
        await gotoServer(page, own(testInfo, 'retry'))
        await page.getByRole('button', { name: 'Try again' }).click()

        await expect(toast(page)).toContainText('Trying again.')
        await expect(page.getByTestId('creation-card')).toContainText('Step 2 of 4')
        await expect(page.getByTestId('creation-error')).toHaveCount(0)
    })
})

test.describe('destroying a provider server', () => {
    test('destroying asks for the name and then marks the server destroying', async ({ page }, testInfo) => {
        const name = own(testInfo, 'destroy')

        await gotoServer(page, name)
        await page.getByRole('button', { name: 'Destroy', exact: true }).click()

        await expect(dialog(page).getByRole('button', { name: 'Destroy server' })).toBeDisabled()

        await dialog(page).getByLabel(`Type ${name} to confirm`).fill(name)

        await expect(dialog(page).getByRole('button', { name: 'Destroy server' })).toBeEnabled()

        await dialog(page).getByRole('button', { name: 'Destroy server' }).click()

        await expect(toast(page)).toContainText('It leaves the fleet once Hetzner Cloud confirms.')
        await expect(page.getByTestId('cloud-task-banner')).toContainText('Destroying')
        await expect(page.getByRole('button', { name: 'Destroy', exact: true })).toHaveCount(0)
    })

    test('the destroy dialog leads to detaching instead', async ({ page }) => {
        await gotoServer(page, 'outpost-01')
        await page.getByRole('button', { name: 'Destroy', exact: true }).click()
        await dialog(page).getByRole('button', { name: 'detach it instead' }).click()

        const detach = page.getByRole('dialog', { name: 'Detach outpost-01?' })

        await expect(detach).toContainText('keeps running at DigitalOcean')

        await detach.getByRole('button', { name: 'Keep it' }).click()

        await expect(dialog(page)).toHaveCount(0)
    })

    test('a destroy that finishes while its page is open lands on the fleet with a confirmation', async ({
        page
    }, testInfo) => {
        const name = own(testInfo, 'gone')

        await page.clock.install()
        await gotoServer(page, name)
        await expect(page.getByTestId('cloud-task-banner')).toContainText(`Destroying ${name}`)

        finishDestroy(name)
        await page.clock.fastForward(65_000)

        await expect(page).toHaveURL(/\/dashboard$/)
        await expect(toast(page)).toContainText(
            `Destroyed. ${name} was deleted at Hetzner Cloud, and billing for it stopped.`
        )
        await expect(page.getByTestId('server-card').getByRole('link', { name, exact: true })).toHaveCount(0)
        await expect(page.getByRole('dialog')).toHaveCount(0)
    })

    test('a failed destroy says why and offers to try again or detach', async ({ page }) => {
        await gotoServer(page, 'destroy-failed')

        const banner = page.getByTestId('cloud-task-banner')

        await expect(banner).toContainText('server is protected')
        await expect(banner.getByRole('button', { name: 'Try again' })).toBeVisible()
        await expect(banner.getByRole('button', { name: 'Detach' })).toBeVisible()
    })
})

test.describe('resizing and rebooting through the provider', () => {
    test('resizing offers bigger sizes with their prices and then marks the server resizing', async ({
        page
    }, testInfo) => {
        await gotoServer(page, own(testInfo, 'resize'))
        await page.getByRole('button', { name: 'Resize', exact: true }).click()

        const size = dialog(page).getByLabel('New size')

        await expect(size).toContainText('cx32')
        await expect(size).toContainText('€6.80/mo')

        await size.selectOption('cx32')
        await dialog(page).getByRole('button', { name: 'Resize to cx32' }).click()

        await expect(toast(page)).toContainText('Resizing ')
        await expect(toast(page)).toContainText('Apps are offline until it starts again.')
        await expect(page.getByTestId('cloud-task-banner')).toContainText('Resizing')
        await expect(page.getByRole('button', { name: 'Resize', exact: true })).toHaveCount(0)
    })

    test('the resize dialog says the disk is kept', async ({ page }) => {
        await gotoServer(page, 'outpost-01')
        await page.getByRole('button', { name: 'Resize', exact: true }).click()

        await expect(dialog(page)).toContainText('The disk stays 50 GB, so you can size back down later.')

        await dialog(page).getByRole('button', { name: 'Keep s-1vcpu-2gb' }).click()

        await expect(dialog(page)).toHaveCount(0)
    })

    test('the resize dialog says when the sizes could not be loaded, and loads them on a second try', async ({
        page
    }) => {
        let refuse = true

        await page.route('**/servers/*/cloud/sizes', (route) =>
            refuse ? route.fulfill({ status: 429, json: { message: 'Too Many Attempts.' } }) : route.continue()
        )
        await gotoServer(page, 'outpost-01')
        await page.getByRole('button', { name: 'Resize', exact: true }).click()

        await expect(dialog(page)).toContainText('Too many requests for a moment. Wait a minute, then try again.')

        refuse = false
        await dialog(page).getByRole('button', { name: 'Try again' }).click()

        await expect(dialog(page).getByLabel('New size')).toBeVisible()
    })

    test('the resize dialog says when the server can no longer be resized', async ({ page }) => {
        await page.route('**/servers/*/cloud/sizes', (route) =>
            route.fulfill({ status: 404, json: { message: 'Not Found' } })
        )
        await gotoServer(page, 'outpost-01')
        await page.getByRole('button', { name: 'Resize', exact: true }).click()

        await expect(dialog(page)).toContainText(
            "Coresmith can't resize outpost-01 right now. Reload the page to see why."
        )

        await dialog(page).getByRole('button', { name: 'Close' }).click()

        await expect(dialog(page)).toHaveCount(0)
    })

    test('the resize dialog says when no size fits', async ({ page }) => {
        await page.route('**/servers/*/cloud/sizes', async (route) => {
            const response = await route.fetch()

            await route.fulfill({ response, json: { ...(await response.json()), sizes: [] } })
        })
        await gotoServer(page, 'outpost-01')
        await page.getByRole('button', { name: 'Resize', exact: true }).click()

        await expect(dialog(page)).toContainText(
            'DigitalOcean has no other size outpost-01 can move to from s-1vcpu-2gb.'
        )
        await expect(dialog(page).getByRole('button', { name: /^Resize to/ })).toHaveCount(0)
    })

    test('a provider server is rebooted from the header', async ({ page }) => {
        await gotoServer(page, 'outpost-01')
        await page.getByRole('button', { name: 'Reboot server' }).click()

        await expect(toast(page)).toContainText('Reboot scheduled.')
    })

    test('a resize that failed says why and can be dismissed', async ({ page }) => {
        await gotoServer(page, 'resize-failed')

        const banner = page.getByTestId('cloud-task-banner')

        await expect(banner).toContainText(
            'Hetzner Cloud refused the request. Hetzner Cloud said: no capacity. resize-failed was started again on cx22.'
        )
        await expect(banner.getByRole('button', { name: 'Dismiss' })).toBeVisible()
    })
})

test.describe('provider servers on a phone', () => {
    test.skip(({ isMobile }) => !isMobile, 'The phone layout and tap sizes apply only below 640px')

    test('the header actions and the task banner can be tapped', async ({ page }) => {
        await gotoServer(page, 'resize-failed')
        await noSidewaysScroll(page)

        for (const name of ['Rename', 'Reboot server', 'Resize', 'Destroy']) {
            const box = await page.getByRole('button', { name, exact: true }).boundingBox()

            expect(box?.height ?? 0, name).toBeGreaterThanOrEqual(44)
        }

        const dismiss = await page
            .getByTestId('cloud-task-banner')
            .getByRole('button', { name: 'Dismiss' })
            .boundingBox()

        expect(dismiss?.height ?? 0).toBeGreaterThanOrEqual(44)
    })

    test('the cloud providers card can be tapped', async ({ page }) => {
        await page.goto('/settings/team')
        await noSidewaysScroll(page)

        const card = page.getByTestId('cloud-providers-card')
        const row = card.getByTestId('cloud-account-row').filter({ hasText: 'Linode' })

        for (const [label, control] of [
            ['Add a provider', card.getByRole('button', { name: 'Add a provider' })],
            ['Rename', row.getByRole('button', { name: 'Rename' })],
            ['Replace token', row.getByRole('button', { name: 'Replace token' })],
            ['Remove Linode', row.getByRole('button', { name: 'Remove Linode' })]
        ] as const) {
            const box = await control.boundingBox()

            expect(box?.height ?? 0, label).toBeGreaterThanOrEqual(44)
            expect(box?.width ?? 0, label).toBeGreaterThanOrEqual(44)
        }
    })

    test('a failed creation can be tapped', async ({ page }) => {
        await gotoServer(page, 'failed-create')
        await noSidewaysScroll(page)

        for (const name of ['Try again', 'Destroy it']) {
            const box = await page.getByRole('button', { name, exact: true }).boundingBox()

            expect(box?.height ?? 0, name).toBeGreaterThanOrEqual(44)
        }
    })
})
