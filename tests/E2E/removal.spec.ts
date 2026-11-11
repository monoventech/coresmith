import type { Page, Route } from '@playwright/test'
import { blockingViolations, dialog, expect, gotoSettings, test, useTheme, withProps } from './support/fixtures'

const INSTALLER = 'curl -fsSL https://get.coresmith.io/install | sudo bash'

async function removalShowing(page: Page, change: Record<string, unknown> = {}) {
    await withProps(page, /\/settings$/, (props) => {
        props.removal = {
            ...(props.removal as Record<string, unknown>),
            servers: 3,
            other_teams: ['Acme Labs', 'Northwind Ops'],
            this_machine_apps: true,
            database: 'sqlite',
            database_name: null,
            two_factor: false,
            helper: true,
            ...change
        }
    })
}

async function openRemoval(page: Page) {
    await gotoSettings(page, 'General')
    await page.getByTestId('remove-coresmith').getByRole('button', { name: 'Remove Coresmith' }).click()
    await expect(dialog(page).getByRole('heading', { name: 'Remove Coresmith?' })).toBeVisible()
}

async function dialogSettled(page: Page) {
    await dialog(page).evaluate((element) =>
        Promise.all(element.getAnimations({ subtree: true }).map((animation) => animation.finished))
    )
}

async function progressPage(page: Page) {
    await gotoSettings(page, 'General')
    await page.goto(`${page.url()}/removal`)
    await expect(page.getByTestId('removal-heading')).toBeVisible()
}

function statusFeed(page: Page, answers: (Record<string, unknown> | 'fail')[]) {
    let call = 0

    return page.route('**/settings/removal/status', async (route: Route) => {
        const answer = answers[Math.min(call, answers.length - 1)]
        call += 1

        if (answer === 'fail') {
            await route.abort()

            return
        }

        await route.fulfill({ json: { stage: null, saved: null, removed: [], failed: [], ...answer } })
    })
}

test.describe('removing Coresmith', () => {
    test.afterEach(({ page }) => page.unrouteAll({ behavior: 'ignoreErrors' }))

    test('owners of a self-hosted install see Remove Coresmith in the Danger zone', async ({ page }) => {
        await gotoSettings(page, 'General')

        const block = page.getByTestId('remove-coresmith')
        await expect(block).toContainText(
            'Removing Coresmith detaches every server. The servers, and everything on them, keep running.'
        )
        await expect(block.getByRole('button', { name: 'Remove Coresmith' })).toBeVisible()
    })

    test('step 1 says what happens with real numbers, and Enter keeps Coresmith', async ({ page }) => {
        await removalShowing(page)
        await openRemoval(page)

        await expect(dialog(page)).toContainText("This is permanent and can't be undone.")
        await expect(dialog(page).getByTestId('removal-what').getByRole('listitem')).toHaveText([
            'Your 3 servers, and everything on them, keep running. Deploys stop until you point another Coresmith at them.',
            'Coresmith takes its key off each server it can reach.',
            'Apps on this machine keep running.',
            'This also removes Coresmith for Acme Labs and Northwind Ops.',
            "Its settings, backups and database are saved in /root on this server first. Everything else of Coresmith's is deleted."
        ])
        await expect(dialog(page).getByRole('button', { name: 'Keep Coresmith' })).toBeFocused()

        await page.keyboard.press('Enter')
        await expect(dialog(page)).toHaveCount(0)
    })

    test('an install on PostgreSQL is told its database stays where it is', async ({ page }) => {
        await removalShowing(page, {
            database: 'pgsql',
            database_name: 'coresmith',
            other_teams: [],
            this_machine_apps: false
        })
        await openRemoval(page)

        await expect(dialog(page).getByTestId('removal-what').getByRole('listitem')).toHaveText([
            'Your 3 servers, and everything on them, keep running. Deploys stop until you point another Coresmith at them.',
            'Coresmith takes its key off each server it can reach.',
            "Its settings and backups are saved in /root on this server first. Everything else of Coresmith's is deleted.",
            'Its PostgreSQL database coresmith stays on its database server.'
        ])
    })

    test('without its helper, removal waits for the installer to run once', async ({ page }) => {
        await removalShowing(page, { helper: false })
        await openRemoval(page)

        const helper = dialog(page).getByTestId('removal-helper')
        await expect(helper).toContainText(
            "Coresmith's removal helper isn't installed. Run the installer once on this server, then come back:"
        )
        await expect(helper).toContainText(INSTALLER)
        await expect(dialog(page).getByRole('button', { name: 'Continue' })).toBeDisabled()
    })

    test('step 2 stays disabled until the address matches', async ({ page }) => {
        await removalShowing(page)
        await openRemoval(page)
        await dialog(page).getByRole('button', { name: 'Continue' }).click()

        await expect(dialog(page).getByRole('heading', { name: 'Are you sure?' })).toBeVisible()
        await expect(dialog(page)).toContainText(
            "There's no undo. Type 127.0.0.1 and your password to remove Coresmith."
        )
        const remove = dialog(page).getByRole('button', { name: 'Remove Coresmith' })
        await expect(remove).toBeDisabled()
        await dialog(page).getByLabel("Coresmith's address").fill('127.0.0.')
        await expect(remove).toBeDisabled()
        await dialog(page).getByLabel("Coresmith's address").fill('127.0.0.1')
        await expect(remove).toBeEnabled()
        await expect(dialog(page).getByLabel('Code from your authenticator app')).toHaveCount(0)
    })

    test('an owner with two-factor on is asked for a code as well', async ({ page }) => {
        await removalShowing(page, { two_factor: true })
        await openRemoval(page)
        await dialog(page).getByRole('button', { name: 'Continue' }).click()

        await expect(dialog(page)).toContainText(
            "There's no undo. Type 127.0.0.1, your password and a code from your authenticator app to remove Coresmith."
        )
        await expect(dialog(page).getByLabel('Code from your authenticator app')).toBeVisible()
    })

    test('Coresmith says why it refuses, inside the dialog', async ({ page }) => {
        await removalShowing(page)
        await openRemoval(page)
        await dialog(page).getByRole('button', { name: 'Continue' }).click()
        await dialog(page).getByLabel("Coresmith's address").fill('127.0.0.1')
        await dialog(page).getByLabel('Your password').fill('password-for-e2e')
        await dialog(page).getByRole('button', { name: 'Remove Coresmith' }).click()

        await expect(dialog(page).getByTestId('removal-refusal')).toHaveText(
            /^A deploy is running for .+\. Wait for it to finish, then try again\.$/
        )
        await expect(page).toHaveURL(/\/settings$/)
    })

    test('the progress page lists each step and ends with Coresmith is gone', async ({ page }) => {
        await statusFeed(page, [
            { stage: 'releasing', saved: '/root/coresmith-uninstalled-20261111-133400' },
            {
                stage: 'stopping',
                saved: '/root/coresmith-uninstalled-20261111-133400',
                removed: ['anvil-01', 'forge-01'],
                failed: [{ name: 'outpost-02', file: '/root/.ssh/authorized_keys', comment: 'coresmith@outpost-02' }]
            },
            'fail'
        ])
        await page.route('**/up', (route) => route.abort())
        await progressPage(page)

        await expect(page.getByTestId('removal-steps')).toContainText(
            'Saved a copy to /root/coresmith-uninstalled-20261111-133400'
        )
        await expect(page.getByTestId('removal-steps')).toContainText("Took Coresmith's key off 2 of 3 servers")
        await expect(page.getByTestId('removal-steps')).toContainText('Stopping Coresmith…')

        await expect(page.getByTestId('removal-heading')).toHaveText('Coresmith is gone', { timeout: 15_000 })
        await expect(page.getByTestId('removal-gone')).toContainText(
            'Your servers keep running. A copy of its settings and backups is in /root/coresmith-uninstalled-20261111-133400 on this server.'
        )
        await expect(
            page.getByTestId('removal-gone').getByText('/root/coresmith-uninstalled-20261111-133400', { exact: true })
        ).toBeVisible()
        await expect(page.getByTestId('removal-unreached')).toHaveText(
            'outpost-02: remove the line ending in coresmith@outpost-02 from /root/.ssh/authorized_keys.'
        )
        await expect(page.getByTestId('removal-gone')).toContainText(
            'If anything was left behind, journalctl -u coresmith-uninstall on this server says what.'
        )
    })

    test('one failed check is not taken for gone', async ({ page }) => {
        await statusFeed(page, [
            { stage: 'releasing', saved: '/root/coresmith-uninstalled-20261111-133400' },
            'fail',
            { stage: 'stopping', saved: '/root/coresmith-uninstalled-20261111-133400' }
        ])
        await page.route('**/up', (route) => route.abort())
        await progressPage(page)

        await expect(page.getByTestId('removal-steps')).toContainText('Stopping Coresmith…', { timeout: 10_000 })
        await expect(page.getByTestId('removal-heading')).toHaveText('Removing Coresmith')
    })

    test('a sole owner on self-hosted no longer sees Close your account', async ({ page }) => {
        await withProps(page, /\/settings$/, (props) => {
            props.account = { refusal: 'Make someone else an owner of Monoven first.', hidden: true }
        })
        await gotoSettings(page, 'General')

        await expect(page.getByTestId('remove-coresmith')).toBeVisible()
        await expect(page.getByTestId('close-account')).toHaveCount(0)
    })

    for (const theme of ['light', 'dark'] as const) {
        test(`removing Coresmith has no serious or critical violations in ${theme}`, async ({ page }) => {
            await useTheme(page, theme)
            await removalShowing(page)
            await gotoSettings(page, 'General')
            expect(await blockingViolations(page), `danger zone in ${theme}`).toEqual([])

            await page.getByTestId('remove-coresmith').getByRole('button', { name: 'Remove Coresmith' }).click()
            await dialogSettled(page)
            expect(await blockingViolations(page), `removal step 1 in ${theme}`).toEqual([])

            await dialog(page).getByRole('button', { name: 'Continue' }).click()
            await expect(dialog(page).getByRole('heading', { name: 'Are you sure?' })).toBeVisible()
            expect(await blockingViolations(page), `removal step 2 in ${theme}`).toEqual([])
        })

        test(`the progress and gone pages have no serious or critical violations in ${theme}`, async ({ page }) => {
            await useTheme(page, theme)
            await statusFeed(page, [
                {
                    stage: 'stopping',
                    saved: '/root/coresmith-uninstalled-20261111-133400',
                    removed: ['anvil-01'],
                    failed: [
                        { name: 'outpost-02', file: '/root/.ssh/authorized_keys', comment: 'coresmith@outpost-02' }
                    ]
                },
                'fail'
            ])
            await page.route('**/up', (route) => route.abort())
            await progressPage(page)
            await expect(page.getByTestId('removal-steps')).toContainText('Stopping Coresmith…')
            expect(await blockingViolations(page), `progress in ${theme}`).toEqual([])

            await expect(page.getByTestId('removal-heading')).toHaveText('Coresmith is gone', { timeout: 15_000 })
            expect(await blockingViolations(page), `gone in ${theme}`).toEqual([])
        })
    }
})
