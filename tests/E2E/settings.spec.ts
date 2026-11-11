import {
    asMember,
    dialog,
    expect,
    expectTabInView,
    gotoSettings,
    NARROW_PHONE,
    own,
    tabComesIntoViewAsStripNarrows,
    tabOverhang,
    tabs,
    tabStaysInViewAsTabsWiden,
    test,
    toast,
    withProps
} from './support/fixtures'

test.describe('settings', () => {
    test('the tabs cover the installation, the team, git, notifications and tokens', async ({ page }) => {
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

    test('the last tab stays in view when the tabs widen', async ({ page }) => {
        await tabStaysInViewAsTabsWiden(page, '/settings/api-tokens', 'Settings', 'API tokens')
    })

    test('the last tab comes into view when the strip narrows', async ({ page }) => {
        await tabComesIntoViewAsStripNarrows(page, '/settings/api-tokens', 'Settings', 'API tokens')
    })

    test('a tab opened from the strip is brought fully into view', async ({ page }) => {
        await page.setViewportSize(NARROW_PHONE)
        await page.goto('/settings')

        const tokens = tabs(page, 'Settings').getByRole('link', { name: 'API tokens', exact: true })

        expect(await tabOverhang(tokens)).toBeGreaterThan(1)

        await tokens.dispatchEvent('click')

        await expect(tokens).toHaveAttribute('aria-current', 'page')
        await expectTabInView(tokens)
    })

    test('the current tab comes back when the strip changes size after a swipe', async ({ page }) => {
        await page.setViewportSize(NARROW_PHONE)
        await page.goto('/settings')

        const strip = tabs(page, 'Settings')
        const general = strip.getByRole('link', { name: 'General', exact: true })

        await expect(general).toHaveAttribute('aria-current', 'page')
        await expect
            .poll(async () => {
                await strip.evaluate((element) => element.scrollTo({ left: element.scrollWidth }))

                return tabOverhang(general)
            })
            .toBeGreaterThan(1)

        await page.setViewportSize({ ...NARROW_PHONE, width: NARROW_PHONE.width + 20 })

        await expectTabInView(general)
    })
})

test.describe('general settings', () => {
    test('the installation card shows the version and what can be changed', async ({ page }) => {
        await gotoSettings(page, 'General')

        await expect(
            page.getByText('Installation, team and notification preferences for Coresmith.', { exact: true })
        ).toBeVisible()
        await expect(page.getByTestId('panel-card').getByRole('heading', { name: 'Installation' })).toBeVisible()

        await expect(page.getByTestId('panel-updates-card')).toContainText(/Coresmith v\d+\.\d+\.\d+ · stable channel/)

        const panel = page.getByTestId('panel-card')

        await expect(panel.getByLabel('Installation name')).toHaveValue('Coresmith')
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
        const name = `${own(testInfo, 'laptop')}${testInfo.retry > 0 ? `-${testInfo.retry}` : ''}`
        const material = Buffer.from(`e2e-key-${testInfo.project.name}-${Date.now()}`).toString('base64')

        await gotoSettings(page, 'General')

        const keys = page.getByTestId('ssh-keys-card')

        await expect(keys).toContainText('maya@monoven')
        await expect(keys.getByTestId('ssh-keys-reach')).toHaveText(
            'Installed on every server you manage, except the machine Coresmith runs on. Password login stays off.'
        )

        await keys.getByRole('button', { name: 'Add key' }).click()
        await expect(dialog(page)).toContainText("It's installed on your servers straight away.")
        await dialog(page).getByLabel('Name').fill(name)
        await dialog(page).getByLabel('Public key').fill(`ssh-ed25519 ${material} me@laptop`)
        await dialog(page).getByRole('button', { name: 'Add key' }).click()

        await expect(toast(page)).toContainText('Saved.')
        await expect(keys).toContainText(name)

        await keys.getByRole('button', { name: `Remove ${name}`, exact: true }).click()

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

    test('an owner alone in a team, who could never close their account, is offered Remove Coresmith instead', async ({
        page
    }) => {
        await gotoSettings(page, 'General')

        await expect(page.getByTestId('close-account')).toHaveCount(0)
        await expect(
            page.getByTestId('remove-coresmith').getByRole('button', { name: 'Remove Coresmith' })
        ).toBeVisible()
        await expect(page.getByTestId('danger-zone').getByRole('button', { name: 'Delete this team' })).toHaveCount(0)
    })

    test('the last owner of a team someone else could take over is told what has to happen first', async ({ page }) => {
        await withProps(page, /\/settings$/, (props) => {
            props.account = { refusal: 'Make someone else an owner of Monoven first.', hidden: false }
        })
        await gotoSettings(page, 'General')

        const closing = page.getByTestId('close-account')

        await expect(closing).toContainText('Make someone else an owner of Monoven first.')
        await expect(closing.getByRole('button', { name: 'Close your account' })).toBeDisabled()
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

    asMember('a deployer can open the dialog that closes the account and keep the account', async ({ page }) => {
        await gotoSettings(page, 'General')

        const closing = page.getByTestId('close-account')

        await expect(closing).toContainText('Your teams keep running without you.')

        await closing.getByRole('button', { name: 'Close your account' }).click()
        await expect(dialog(page)).toContainText('Close your account?')
        await dialog(page).getByRole('button', { name: 'Keep my account' }).click()

        await expect(dialog(page)).toHaveCount(0)
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
