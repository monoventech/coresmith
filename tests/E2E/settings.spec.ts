import { asMember, dialog, expect, gotoSettings, own, tabs, test, toast } from './support/fixtures'

test.describe('settings', () => {
    test('the tabs cover the panel, the team, git, notifications and tokens', async ({ page }) => {
        await gotoSettings(page, 'General')

        const sections = tabs(page, 'Settings')

        await expect(sections.getByRole('link', { name: 'General', exact: true })).toHaveAttribute(
            'aria-current',
            'page'
        )

        for (const [label, url] of [
            ['Team', /\/settings\/team$/],
            ['Git', /\/settings\/git$/],
            ['Notifications', /\/settings\/notifications$/],
            ['API tokens', /\/settings\/api-tokens$/],
            ['General', /\/settings$/]
        ] as const) {
            await tabs(page, 'Settings').getByRole('link', { name: label, exact: true }).click()
            await page.waitForURL(url)
            await expect(tabs(page, 'Settings').getByRole('link', { name: label, exact: true })).toHaveAttribute(
                'aria-current',
                'page'
            )
            await expect(page.getByRole('heading', { level: 1, name: 'Settings' })).toBeVisible()
        }

        await expect(tabs(page, 'Settings').getByRole('link', { name: 'Billing' })).toHaveCount(0)
    })
})

test.describe('general settings', () => {
    test('the panel card shows the version and what can be changed', async ({ page }) => {
        await gotoSettings(page, 'General')

        await expect(page.getByTestId('panel-updates-card')).toContainText(/Coresmith v\d+\.\d+\.\d+ · stable channel/)

        const panel = page.getByTestId('panel-card')

        await expect(panel.getByLabel('Panel name')).toHaveValue('Coresmith')
        await expect(panel.getByLabel('Timezone')).toBeVisible()
    })

    test('the alert email is saved', async ({ page }, testInfo) => {
        const email = `${own(testInfo, 'ops')}@monoven.io`

        await gotoSettings(page, 'General')

        const panel = page.getByTestId('panel-card')

        await panel.getByLabel('Alert email').fill(email)
        await panel.getByRole('button', { name: 'Save' }).click()

        await expect(toast(page)).toContainText('Saved.')

        await page.reload()
        await expect(page.getByTestId('panel-card').getByLabel('Alert email')).toHaveValue(email)
    })

    test('a backup is taken on demand', async ({ page }) => {
        await gotoSettings(page, 'General')

        const backups = page.getByTestId('backups-card')

        await expect(backups).toContainText('Taken nightly at 04:00 and before every update.')
        await backups.getByRole('button', { name: 'Back up now' }).click()

        await expect(toast(page)).toContainText('Backing up.')
    })

    test('an ssh key is added and removed', async ({ page }, testInfo) => {
        const name = own(testInfo, 'laptop')
        const material = Buffer.from(`e2e-key-${testInfo.project.name}-${Date.now()}`).toString('base64')

        await gotoSettings(page, 'General')

        const keys = page.getByTestId('ssh-keys-card')

        await expect(keys).toContainText('maya@monoven')
        await expect(keys.getByTestId('ssh-keys-reach')).toHaveText(
            'Installed on every server you manage. Password login stays off.'
        )

        await keys.getByRole('button', { name: 'Add key' }).click()
        await expect(dialog(page)).toContainText("It's installed on your servers straight away.")
        await dialog(page).getByLabel('Name').fill(name)
        await dialog(page).getByLabel('Public key').fill(`ssh-ed25519 ${material} me@laptop`)
        await dialog(page).getByRole('button', { name: 'Add key' }).click()

        await expect(toast(page)).toContainText('Saved.')
        await expect(keys).toContainText(name)

        await keys.getByRole('button', { name: `Remove ${name}` }).click()

        await expect(toast(page)).toContainText('Removed.')
        await expect(keys).not.toContainText(name)
    })

    test('a malformed ssh key is refused with what to paste instead', async ({ page }) => {
        await gotoSettings(page, 'General')

        await page.getByTestId('ssh-keys-card').getByRole('button', { name: 'Add key' }).click()
        await dialog(page).getByLabel('Name').fill('Bad key')
        await dialog(page).getByLabel('Public key').fill('this is not a key')
        await dialog(page).getByRole('button', { name: 'Add key' }).click()

        await expect(dialog(page)).toContainText("That isn't an OpenSSH public key.")
    })

    test('an owner of a team is told to hand it over before deleting the account', async ({ page }) => {
        await gotoSettings(page, 'General')

        await expect(page.getByRole('main')).toContainText('Hand the teams you own to someone else first.')

        await page.getByRole('button', { name: 'Delete your account' }).click()
        await expect(dialog(page)).toContainText('Delete your account?')
        await dialog(page).getByRole('button', { name: 'Keep my account' }).click()

        await expect(dialog(page)).toHaveCount(0)
    })
})

asMember.describe('ssh keys as a deployer', () => {
    asMember('a deployer is told their keys are not installed on any server', async ({ page }) => {
        await gotoSettings(page, 'General')

        const keys = page.getByTestId('ssh-keys-card')

        await expect(keys.getByTestId('ssh-keys-reach')).toHaveText(
            "Deployers don't get shell access, so these aren't installed on any server."
        )

        await keys.getByRole('button', { name: 'Add key' }).click()
        await expect(dialog(page)).toContainText("It's installed once you're an owner or admin of a team.")
    })
})

test.describe('notification settings', () => {
    test('failures always notify and cannot be turned off', async ({ page }) => {
        await gotoSettings(page, 'Notifications')

        const card = page.getByTestId('notifications-card')

        await expect(card).toContainText('Sent to owner@coresmith.test.')
        await expect(page.getByTestId('notification-deploy_failed').getByRole('switch')).toBeChecked()
        await expect(page.getByTestId('notification-deploy_failed').getByRole('switch')).toBeDisabled()
    })

    test('a preference is saved and kept', async ({ page }) => {
        await gotoSettings(page, 'Notifications')

        const digest = page.getByTestId('notification-weekly_digest').getByRole('switch')
        const before = await digest.getAttribute('aria-checked')

        await digest.click()
        await expect(toast(page)).toContainText('Saved.')

        await page.reload()

        const after = page.getByTestId('notification-weekly_digest').getByRole('switch')

        await expect(after).toHaveAttribute('aria-checked', before === 'true' ? 'false' : 'true')

        await after.click()
        await expect(page.getByTestId('notification-weekly_digest').getByRole('switch')).toHaveAttribute(
            'aria-checked',
            before ?? 'false'
        )
    })
})

test.describe('a self-hosted panel has no billing', () => {
    test('the billing page is not there', async ({ page }) => {
        const response = await page.goto('/settings/billing')

        expect(response?.status()).toBe(404)
    })
})
