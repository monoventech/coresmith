import type { Page } from '@playwright/test'
import { asMember, blockingViolations, expect, gotoApplication, isMobile, nav, noSidewaysScroll, own, test, toast, useTheme } from './support/fixtures'

async function gotoDeployments(page: Page, mobile: boolean) {
    await page.goto('/dashboard')
    await nav(page).getByRole('link', { name: mobile ? 'Deploys' : 'Deployments', exact: true }).click()
    await page.waitForURL(/\/deployments$/)
    await expect(page.getByRole('heading', { level: 1, name: 'Deployments' })).toBeVisible()
}

function filters(page: Page) {
    return page.getByRole('navigation', { name: 'Filter deployments' })
}

function rows(page: Page) {
    return page.getByTestId('deployment-row')
}

test.describe('the deployments list', () => {
    test('every deploy across the fleet is listed with the running one pinned on top', async ({ page }, testInfo) => {
        await gotoDeployments(page, isMobile(testInfo))

        await expect(filters(page).getByRole('link', { name: /^All\s*\d+$/ })).toHaveAttribute('aria-current', 'page')
        await expect(rows(page).first()).toContainText('atlas-staging')
        await expect(rows(page).first()).toContainText('Running')
        await expect(rows(page).first()).toContainText('Watch')
    })

    test('the failed filter shows only failures, and says they had no impact', async ({ page }, testInfo) => {
        await gotoDeployments(page, isMobile(testInfo))

        await filters(page).getByRole('link', { name: /^Failed\s*\d+$/ }).click()
        await page.waitForURL(/status=failed/)

        await expect(rows(page).filter({ hasText: 'storefront' })).toHaveCount(1)

        for (const row of await rows(page).all()) {
            await expect(row).toContainText('Failed')
        }
    })

    test('the running filter shows the deploy in progress', async ({ page }, testInfo) => {
        await gotoDeployments(page, isMobile(testInfo))

        await filters(page).getByRole('link', { name: /^Running\s*\d+$/ }).click()
        await page.waitForURL(/status=running/)

        await expect(rows(page)).toHaveCount(1)
        await expect(rows(page).first()).toContainText('atlas-staging')
    })

    test('a row opens its deploy and the back link returns to the list', async ({ page }, testInfo) => {
        await gotoDeployments(page, isMobile(testInfo))

        await rows(page).filter({ hasText: 'ledger' }).first().click()

        await expect(page.getByRole('heading', { level: 1, name: 'Deploying ledger' })).toBeVisible()
        await expect(page.getByTestId('back-link')).toHaveText('← Deployments')

        await page.getByTestId('back-link').click()
        await page.waitForURL(/\/deployments$/)
    })

    test('the list does not scroll sideways', async ({ page }, testInfo) => {
        await gotoDeployments(page, isMobile(testInfo))

        await noSidewaysScroll(page)
    })
})

test.describe('a deploy', () => {
    test('a running deploy shows each phase and refreshes without a socket', async ({ page }) => {
        await page.goto('/dashboard')
        await page.getByTestId('live-deploy').click()

        await expect(page.getByRole('heading', { level: 1, name: 'Deploying atlas-staging' })).toBeVisible()
        await expect(page.getByTestId('deployment-status')).toHaveText('Running')

        const rail = page.getByTestId('phase-rail')

        await expect(rail.locator('[data-phase]')).toHaveCount(6)
        await expect(rail.locator('[data-phase="clone"]')).toHaveAttribute('data-state', 'done')
        await expect(rail.locator('[data-phase][data-state="running"]')).toHaveCount(1)
        await expect(rail.locator('[data-phase="migrate"]')).toHaveAttribute('data-state', 'running')
        await expect(page.getByLabel('Deployment output')).toContainText('migrate: running migrations')
        await expect(page.getByTestId('log-transport')).toHaveText('Refreshing every 3s')
    })

    test('a failed deploy says which phase failed and that nothing changed', async ({ page }) => {
        await gotoApplication(page, 'forge-01', 'storefront')
        await page.getByTestId('failed-banner').getByRole('link', { name: 'Read the log' }).click()

        await expect(page.getByTestId('deployment-status')).toContainText('Failed')
        await expect(page.getByTestId('failure-banner')).toContainText('The build failed, so nothing changed.')
        await expect(page.getByTestId('failure-banner')).toContainText('users never saw an error page')
        await expect(page.getByTestId('phase-rail').locator('[data-phase="build"]')).toHaveAttribute('data-state', 'failed')
        await expect(page.getByLabel('Deployment output')).toContainText('deploy failed. the current release is untouched.')
        await expect(page.getByTestId('log-transport')).toHaveText('Finished')
    })

    test('a failed deploy can be retried', async ({ page }, testInfo) => {
        const name = own(testInfo, 'deploy')

        await gotoApplication(page, 'bench-01', name)
        await page.getByTestId('failed-banner').getByRole('link', { name: 'Read the log' }).click()
        await page.getByRole('button', { name: 'Retry deploy' }).click()

        await expect(toast(page)).toContainText(`Deploying ${name}.`)
        await expect(page.getByTestId('deployment-status')).toHaveText('Queued')
        await expect(page.getByLabel('Deployment output')).toContainText('Waiting for a worker to pick up the deploy')
    })

    test('a finished deploy links to the releases it made', async ({ page }, testInfo) => {
        await gotoDeployments(page, isMobile(testInfo))

        await filters(page).getByRole('link', { name: /^Deployed\s*\d+$/ }).click()
        await rows(page).filter({ hasText: 'warehouse' }).first().click()

        await expect(page.getByTestId('deployment-status')).toHaveText('Deployed')
        await page.getByRole('link', { name: 'View releases' }).click()

        await expect(page.getByRole('heading', { level: 1, name: 'Releases · warehouse' })).toBeVisible()
    })

    test('the output can be focused so it scrolls from the keyboard', async ({ page }) => {
        await page.goto('/dashboard')
        await page.getByTestId('live-deploy').click()

        const output = page.getByLabel('Deployment output')

        await output.focus()
        await expect(output).toBeFocused()
    })

    test('a long log does not scroll the page sideways', async ({ page }) => {
        await gotoApplication(page, 'forge-01', 'storefront')
        await page.getByTestId('failed-banner').getByRole('link', { name: 'Read the log' }).click()

        await noSidewaysScroll(page)
    })
})

asMember.describe('deploys as a member', () => {
    asMember('a member can read a deploy log', async ({ page }) => {
        await page.goto('/dashboard')
        await page.getByTestId('live-deploy').click()

        await expect(page.getByLabel('Deployment output')).toContainText('clone: fetching via deploy key')
    })
})

test.describe('a deploy that failed with a known reason', () => {
    test('a release that crashed after going live says so and offers a roll back', async ({ page }) => {
        await gotoApplication(page, 'bench-01', 'crashy')

        await expect(page.getByTestId('failed-banner')).toContainText('The last deploy went live but did not finish.')

        await page.getByTestId('failed-banner').getByRole('link', { name: 'Read the log' }).click()

        await expect(page.getByTestId('failure-reason')).toContainText('Crash loop: coresmith-app-crashy-fpm kept stopping')
        await expect(page.getByTestId('phase-rail')).not.toContainText('failed')
        await expect(page.getByTestId('deployment-log')).toContainText('Class "App\\Billing\\Gateway" not found')

        await page.getByRole('link', { name: 'Roll back' }).filter({ visible: true }).first().click()
        await page.waitForURL(/\/releases$/)
    })

    test('a clone that failed names the branch it could not find', async ({ page }) => {
        await gotoApplication(page, 'bench-01', 'wrong-branch')
        await page.getByTestId('failed-banner').getByRole('link', { name: 'Read the log' }).click()

        await expect(page.getByTestId('failure-reason')).toContainText('The clone failed, so nothing changed.')
        await expect(page.getByTestId('failure-reason')).toContainText('Branch release/2.0 is not in the repository.')
        await expect(page.getByRole('link', { name: 'Roll back' })).toHaveCount(0)
    })
})

test.describe('a deploy that installs a runtime first', () => {
    test('the rail shows dependencies running and the log says what is compiling', async ({ page }) => {
        try {
            await page.goto('/acme/dashboard')
            await page.getByTestId('live-deploy').click()

            await expect(page.locator('[data-phase="deps"]')).toHaveAttribute('data-state', 'running')
            await expect(page.getByTestId('deployment-log')).toContainText('installing PHP 8.5.10, compiling it, usually 5–15 minutes the first time')
        } finally {
            await page.goto('/monoven/dashboard')
        }
    })

    for (const theme of ['light', 'dark'] as const) {
        test(`it has no serious or critical accessibility violations in ${theme}`, async ({ page }) => {
            try {
                await useTheme(page, theme)
                await page.goto('/acme/dashboard')
                await page.getByTestId('live-deploy').click()
                await page.getByTestId('phase-rail').waitFor()

                expect(await blockingViolations(page)).toEqual([])
            } finally {
                await page.goto('/monoven/dashboard')
            }
        })
    }
})
