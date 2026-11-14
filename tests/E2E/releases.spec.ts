import type { Page } from '@playwright/test'
import { asMember, dialog, expect, expectWayBack, nav, test, toast } from './support/fixtures'

async function gotoReleases(page: Page) {
    await page.goto('/dashboard')
    await nav(page).getByRole('link', { name: 'Releases', exact: true }).click()
    await page.waitForURL(/\/releases$/)
}

async function gotoReleasesOf(page: Page, application: string) {
    await gotoReleases(page)
    await expect(page.getByRole('heading', { level: 1, name: 'Releases · atlas' })).toBeVisible()
    await page
        .getByRole('navigation', { name: 'Applications' })
        .getByRole('link', { name: application, exact: true })
        .click()
    await expect(page.getByRole('heading', { level: 1, name: `Releases · ${application}` })).toBeVisible()
}

function rows(page: Page) {
    return page.getByTestId('release-row')
}

test.describe('releases', () => {
    test('the navigation opens the most recently released app', async ({ page }) => {
        await gotoReleases(page)

        await expect(page.getByRole('heading', { level: 1, name: 'Releases · atlas' })).toBeVisible()
        await expect(nav(page).getByRole('link', { name: 'Releases', exact: true })).toHaveAttribute(
            'aria-current',
            'page'
        )
    })

    test('each release shows its directory, tag and signature, with the live one marked', async ({ page }) => {
        await gotoReleases(page)

        await expect(rows(page)).toHaveCount(5)

        const current = rows(page).filter({ hasText: 'current →' })

        await expect(current).toHaveCount(1)
        await expect(current).toHaveAttribute('data-current', 'true')
        await expect(current).toContainText(/releases\/\d{8}-\d{4}/)
        await expect(rows(page).filter({ hasText: 'v2.15.0-beta.2' })).toContainText('unsigned')
    })

    test('the app chips switch between apps', async ({ page }) => {
        await gotoReleases(page)

        await page
            .getByRole('navigation', { name: 'Applications' })
            .getByRole('link', { name: 'ledger', exact: true })
            .click()

        await expect(page.getByRole('heading', { level: 1, name: 'Releases · ledger' })).toBeVisible()
        await expect(
            page.getByRole('navigation', { name: 'Applications' }).getByRole('link', { name: 'ledger', exact: true })
        ).toHaveAttribute('aria-current', 'page')
    })

    test('rolling back asks first and says what it will do', async ({ page }) => {
        await gotoReleases(page)

        await rows(page).filter({ hasText: 'v2.13.2' }).getByRole('button', { name: 'Roll back to this' }).click()

        await expect(dialog(page)).toContainText('Roll back to v2.13.2?')
        await expect(dialog(page)).toContainText('It takes about a second, and nothing is rebuilt.')
        await expect(dialog(page).getByText(/^releases\/\d{8}-\d{6}$/)).toHaveCSS('white-space', 'nowrap')
        await expect(dialog(page)).toContainText('Migrations already applied are not reversed.')

        await dialog(page).getByRole('button', { name: 'Cancel' }).click()
        await expect(dialog(page)).toHaveCount(0)
    })

    test('a confirmed rollback is queued', async ({ page }) => {
        await gotoReleases(page)

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
        await gotoReleases(page)

        await expect(rows(page).getByRole('button', { name: 'Roll back to this' }).first()).toBeVisible()
    })
})
