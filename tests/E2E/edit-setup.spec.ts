import type { Page } from '@playwright/test'
import {
    blockingViolations,
    expect,
    expectWayBack,
    gotoApplication,
    main,
    own,
    test,
    toast,
    useTheme
} from './support/fixtures'

function setupCard(page: Page) {
    return page.getByTestId('setup-card')
}

function branch(page: Page) {
    return page.getByLabel('Branch', { exact: true })
}

test.describe("an app's setup", () => {
    test('is changed from the app page, and the next deploy waits for a check of the new setup', async ({
        page
    }, testInfo) => {
        const app = own(testInfo, 'setup')
        const next = `release-${Date.now().toString(36)}`

        await gotoApplication(page, 'bench-01', app)
        await expect(setupCard(page)).toContainText('What Coresmith deploys and how. Changes apply on the next deploy.')
        await expect(setupCard(page)).toContainText(`github.com/monoventech/${app}`)
        await setupCard(page).getByRole('link', { name: 'Edit setup' }).click()

        await expect(main(page)).toContainText(`${app} on bench-01. Changes apply on the next deploy.`)
        await expect(page.getByRole('heading', { level: 1, name: 'Edit setup' })).toBeVisible()
        await expect(page.getByRole('textbox', { name: 'Repository', exact: true })).toHaveValue(
            `https://github.com/monoventech/${app}`
        )
        await expect(page.getByLabel('Name', { exact: true })).toHaveCount(0)
        await expect(page.getByLabel('Domain')).toHaveCount(0)
        await expectWayBack(page, app)

        await branch(page).fill(next)
        await page.getByRole('button', { name: 'Save setup' }).click()

        await expect(toast(page)).toContainText('Saved. Check the repository before the next deploy.')
        await expect(setupCard(page)).toContainText(new RegExp(`Branch\\s*${next}`))

        const banner = page.getByTestId('repository-check-banner')

        await expect(banner).toContainText(
            'The setup changed since Coresmith last read this repository, so the next deploy waits for a check.'
        )
        await expect(banner.getByRole('button', { name: 'Check the repository' })).toBeVisible()
        await expect(page.getByRole('button', { name: 'Deploy', exact: true })).toBeDisabled()
    })

    test('a branch git would refuse is refused next to the field and nothing is saved', async ({ page }, testInfo) => {
        await gotoApplication(page, 'bench-01', own(testInfo, 'setup'))
        await setupCard(page).getByRole('link', { name: 'Edit setup' }).click()
        await branch(page).fill('two words')
        await page.getByRole('button', { name: 'Save setup' }).click()

        await expect(branch(page)).toHaveAttribute('aria-invalid', 'true')
        await expect(main(page)).toContainText(
            'That is not a branch name Git accepts. Use something like main or release/2.0.'
        )
        await expect(branch(page)).toHaveValue('two words')
    })

    test('an app from GitHub shows its repository, which stays as it is', async ({ page }) => {
        await gotoApplication(page, 'bench-01', 'linked-app')
        await setupCard(page).getByRole('link', { name: 'Edit setup' }).click()

        const repository = page.getByTestId('setup-repository')

        await expect(repository).toContainText('monoventech/relay')
        await expect(repository).toContainText(
            'From GitHub on monoventech. To deploy a different repository, add it as a new app.'
        )
        await expect(page.getByRole('textbox', { name: 'Repository', exact: true })).toHaveCount(0)
        await expect(branch(page)).toHaveValue('trunk')
    })

    test('a failed deploy links to the setup from the app and from its log', async ({ page }) => {
        await gotoApplication(page, 'forge-01', 'storefront')

        const banner = page.getByTestId('failed-banner')

        await expect(banner.getByRole('link', { name: 'Edit setup' })).toBeVisible()
        await banner.getByRole('link', { name: 'Read the log' }).click()
        await expect(page.getByRole('heading', { level: 1, name: 'Deploying storefront' })).toBeVisible()

        await main(page).getByRole('link', { name: 'Edit setup' }).click()

        await expect(main(page)).toContainText('storefront on forge-01. Changes apply on the next deploy.')
    })

    for (const theme of ['light', 'dark'] as const) {
        test(`the setup form is accessible in ${theme}`, async ({ page }, testInfo) => {
            await useTheme(page, theme)
            await gotoApplication(page, 'bench-01', own(testInfo, 'setup'))
            await setupCard(page).getByRole('link', { name: 'Edit setup' }).click()
            await expect(page.getByRole('button', { name: 'Save setup' })).toBeVisible()

            expect(await blockingViolations(page)).toEqual([])
        })
    }
})
