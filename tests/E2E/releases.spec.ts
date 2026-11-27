import type { Page } from '@playwright/test'
import {
    asMember,
    blockingViolations,
    dialog,
    expect,
    expectWayBack,
    isMobile,
    nav,
    noSidewaysScroll,
    test,
    toast,
    useTheme
} from './support/fixtures'

async function gotoReleases(page: Page) {
    await page.goto('/dashboard')
    await nav(page).getByRole('link', { name: 'Releases', exact: true }).click()
    await page.waitForURL(/\/releases$/)
    await expect(page.getByRole('heading', { level: 1, name: 'Releases', exact: true })).toBeVisible()
}

function appRow(page: Page, name: string) {
    return page.getByTestId('releases-app').filter({ has: page.getByText(name, { exact: true }) })
}

async function gotoReleasesOf(page: Page, application: string) {
    await gotoReleases(page)
    await page.getByRole('searchbox', { name: 'Search apps' }).fill(application)
    await page.waitForURL((url) => url.searchParams.get('q') === application)
    await appRow(page, application).click()
    await expect(page.getByRole('heading', { level: 1, name: `Releases · ${application}` })).toBeVisible()
}

function rows(page: Page) {
    return page.getByTestId('release-row')
}

test.describe('releases', () => {
    test('the navigation opens every app with its live release, grouped by project', async ({ page }) => {
        await gotoReleases(page)

        await expect(nav(page).getByRole('link', { name: 'Releases', exact: true })).toHaveAttribute(
            'aria-current',
            'page'
        )
        await expect(page.getByTestId('releases-group').first()).toBeVisible()
        await expect(page.getByTestId('releases-group').last()).toHaveAccessibleName('No project')
        await expect(appRow(page, 'atlas')).toContainText(/releases\/\d{8}-\d{6}/)
        await expect(page.getByTestId('releases-count')).toHaveText(/^\d+ apps$/)
    })

    test("searching narrows the list, a row opens that app's releases, and the way back returns to the list", async ({
        page
    }, testInfo) => {
        await gotoReleases(page)

        await page.getByRole('searchbox', { name: 'Search apps' }).fill('ledg')

        await expect(page.getByTestId('releases-app')).toHaveCount(1)
        await expect(page.getByTestId('releases-count')).toHaveText(/^1 of \d+ apps$/)

        await appRow(page, 'ledger').click()
        await expect(page.getByRole('heading', { level: 1, name: 'Releases · ledger' })).toBeVisible()

        if (isMobile(testInfo)) {
            await page.getByTestId('back-link').click()
        } else {
            await nav(page).getByRole('link', { name: 'Releases', exact: true }).click()
        }

        await expect(page.getByRole('heading', { level: 1, name: 'Releases', exact: true })).toBeVisible()
    })

    test('the list names its columns, shows what each live release holds, and marks an app that was rolled back', async ({
        page
    }, testInfo) => {
        await gotoReleases(page)

        if (!isMobile(testInfo)) {
            await expect(page.getByTestId('releases-group').first()).toContainText(
                /App\s+Server\s+Live release\s+Went live/
            )
            await expect(appRow(page, 'atlas').getByTestId('releases-commit')).toHaveText(
                /^v\d+\.\d+\.\d+\s+·\s+[0-9a-f]{7}\s+\S/
            )
        }

        await page.getByRole('searchbox', { name: 'Search apps' }).fill('rolled-back')

        await expect(appRow(page, 'rolled-back').getByTestId('releases-rolled-back')).toHaveText('rolled back')
        await expect(appRow(page, 'dev-mode')).toHaveCount(0)
    })

    test('a search that matches nothing says so and clears in one press', async ({ page }) => {
        await gotoReleases(page)

        await page.getByRole('searchbox', { name: 'Search apps' }).fill('no-such-app')

        await expect(page.getByText('No app matches “no-such-app”.')).toBeVisible()
        await page.getByRole('button', { name: 'Clear the search' }).click()
        await expect(appRow(page, 'atlas')).toBeVisible()
    })

    test('apps come fifty a page, and search finds one on any page, with no sideways scroll', async ({ page }) => {
        await gotoReleases(page)

        await expect(page.getByTestId('releases-app')).toHaveCount(50)
        await expect(page.getByTestId('pager-range')).toHaveText(/^1–50 of \d+ apps$/)
        await expect(appRow(page, 'warehouse')).toHaveCount(0)
        await noSidewaysScroll(page)

        await page.getByTestId('pager-older').click()
        await page.waitForURL((url) => url.searchParams.get('page') === '2')
        await expect(page.getByTestId('pager-range')).toHaveText(/^51–100 of \d+ apps$/)

        await page.getByRole('searchbox', { name: 'Search apps' }).fill('warehouse')

        await expect(appRow(page, 'warehouse')).toBeVisible()
        await expect(page.getByTestId('releases-count')).toHaveText(/^1 of \d+ apps$/)
        await page.waitForURL((url) => url.searchParams.get('q') === 'warehouse' && !url.searchParams.has('page'))
    })

    for (const theme of ['light', 'dark'] as const) {
        test(`the list of apps is accessible in ${theme}`, async ({ page }) => {
            await useTheme(page, theme)
            await gotoReleases(page)

            expect(await blockingViolations(page)).toEqual([])
        })
    }

    test('each release shows its directory, what changed, its tag and signature, with the live one marked', async ({
        page
    }, testInfo) => {
        await gotoReleasesOf(page, 'atlas')

        await expect(rows(page)).toHaveCount(5)

        const current = rows(page).filter({ hasText: 'current →' })

        await expect(current).toHaveCount(1)
        await expect(current).toHaveAttribute('data-current', 'true')
        await expect(current).toContainText(/releases\/\d{8}-\d{4}/)
        await expect(current.getByTestId('release-subject')).not.toBeEmpty()
        await expect(current).toContainText(/commit [0-9a-f]{7} by \S/)

        if (isMobile(testInfo)) {
            await expect(current).toContainText(/v\d+\.\d+\.\d+\s+·\s+main\s+·\s+\S/)
        } else {
            await expect(rows(page).filter({ hasText: 'v2.15.0-beta.2' })).toContainText('unsigned')
        }
    })

    test("an app's releases switch to another app from a search box", async ({ page }) => {
        await gotoReleasesOf(page, 'atlas')

        await page.getByRole('button', { name: "Show another app's releases" }).click()
        await expect(page.getByTestId('app-switcher-more')).toHaveText(/^Showing 20 of \d+\. Type to narrow it down\.$/)
        await expect(page.getByTestId('app-switcher').getByRole('option')).toHaveCount(20)
        await page.getByRole('combobox', { name: 'Search apps' }).fill('ledger')
        await expect(page.getByTestId('app-switcher-more')).toHaveCount(0)
        await page
            .getByTestId('app-switcher')
            .getByRole('option', { name: /^ledger\b/ })
            .first()
            .click()

        await expect(page.getByRole('heading', { level: 1, name: 'Releases · ledger' })).toBeVisible()
    })

    for (const theme of ['light', 'dark'] as const) {
        test(`the open app switcher is accessible in ${theme}`, async ({ page }) => {
            await useTheme(page, theme)
            await gotoReleasesOf(page, 'atlas')
            await page.getByRole('button', { name: "Show another app's releases" }).click()
            await expect(page.getByTestId('app-switcher').getByRole('option').first()).toBeVisible()

            expect(await blockingViolations(page)).toEqual([])
        })
    }

    test('rolling back asks first and says what it will do', async ({ page }) => {
        await gotoReleasesOf(page, 'atlas')

        await rows(page).filter({ hasText: 'v2.13.2' }).getByRole('button', { name: 'Roll back to this' }).click()

        await expect(dialog(page)).toContainText('Roll back to v2.13.2?')
        await expect(dialog(page)).toContainText('goes live again in about a second, and nothing is rebuilt.')
        await expect(dialog(page).getByText(/^releases\/\d{8}-\d{6}$/)).toHaveCSS('white-space', 'nowrap')
        await expect(dialog(page)).toContainText('Migrations that already ran are not undone.')

        await dialog(page).getByRole('button', { name: 'Cancel' }).click()
        await expect(dialog(page)).toHaveCount(0)
    })

    test('a confirmed rollback is queued', async ({ page }) => {
        await gotoReleasesOf(page, 'atlas')

        await rows(page).filter({ hasText: 'v2.13.2' }).getByRole('button', { name: 'Roll back to this' }).click()
        await dialog(page).getByRole('button', { name: 'Roll back' }).click()

        await expect(toast(page)).toContainText('Nothing is rebuilt.')
        await expect(dialog(page)).toHaveCount(0)
    })

    test('a rollback waits for a deploy that is queued', async ({ page }) => {
        await gotoReleasesOf(page, 'held-app')

        const live = rows(page).filter({ hasText: 'v1.1.0' })

        await expect(live).toHaveAttribute('data-current', 'true')

        await rows(page).filter({ hasText: 'v1.0.0' }).getByRole('button', { name: 'Roll back to this' }).click()
        await dialog(page).getByRole('button', { name: 'Roll back' }).click()

        await expect(toast(page)).toContainText(
            'A deploy is running for held-app. Cancel it or let it finish, then roll back.'
        )
        await expect(dialog(page)).toHaveCount(0)
        await expect(live).toHaveAttribute('data-current', 'true')
        await expect(rows(page).filter({ hasText: 'v1.0.0' })).toHaveAttribute('data-current', 'false')
    })

    test('an app running an older release says it was rolled back', async ({ page }) => {
        await gotoReleasesOf(page, 'rolled-back')

        await expect(page.getByTestId('rolled-back')).toContainText('Rolled back.')
        await expect(rows(page).filter({ hasText: 'v1.4.0' })).toHaveAttribute('data-current', 'true')
        await expect(
            rows(page).filter({ hasText: 'v1.5.0' }).getByRole('button', { name: 'Roll back to this' })
        ).toBeVisible()
    })

    test('a live release whose deploy failed is listed as current, and nothing claims a rollback', async ({ page }) => {
        await gotoReleasesOf(page, 'dev-mode')

        const current = rows(page).filter({ hasText: 'current →' })

        await expect(rows(page)).toHaveCount(2)
        await expect(current).toHaveCount(1)
        await expect(current).toHaveAttribute('data-current', 'true')
        await expect(current).toContainText('commit 5e1d0a2 by Priya Nair')
        await expect(page.getByTestId('rolled-back')).toHaveCount(0)
        await expect(
            rows(page).filter({ hasText: 'v1.2.0' }).getByRole('button', { name: 'Roll back to this' })
        ).toBeVisible()
    })

    test('the palette can start a rollback straight to the confirmation', async ({ page }) => {
        await page.goto('/dashboard')
        await expect(page.getByRole('heading', { level: 1, name: 'Your fleet' })).toBeVisible()
        await page.keyboard.press('ControlOrMeta+K')

        const palette = page.getByRole('dialog', { name: 'Command palette' })

        await palette.getByRole('option').first().waitFor()
        await page.keyboard.type('Roll back atlas')
        await page.keyboard.press('Enter')

        await expect(page.getByRole('heading', { level: 1, name: 'Releases · atlas' })).toBeVisible()
        await expect(page.getByRole('dialog', { name: /^Roll back to v2\.13\.2\?$/ })).toBeVisible()
    })

    test('deploying from releases opens the deploy and returns to releases', async ({ page }) => {
        await gotoReleasesOf(page, 'rolled-back')
        await page.getByRole('button', { name: 'Deploy', exact: true }).click()

        await expect(page.getByRole('heading', { level: 1, name: 'Deploying rolled-back' })).toBeVisible()
        await expectWayBack(page, 'Releases')
    })
})

asMember.describe('releases as a member', () => {
    asMember('a deployer may roll back', async ({ page }) => {
        await gotoReleasesOf(page, 'atlas')

        await expect(rows(page).getByRole('button', { name: 'Roll back to this' }).first()).toBeVisible()
    })
})
