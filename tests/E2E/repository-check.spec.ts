import { blockingViolations, expect, gotoApplication, own, test, toast, useTheme } from './support/fixtures'

test.describe('Repository check before the first deploy', () => {
    test('an app Coresmith has not read waits for its check, and Deploy says why', async ({ page }, testInfo) => {
        await gotoApplication(page, 'bench-01', own(testInfo, 'unchecked'))

        const banner = page.getByTestId('repository-check-banner')

        await expect(banner).toContainText(
            "Coresmith hasn't read this repository yet, so the first deploy waits for a check."
        )
        await expect(banner).toContainText('Add the deploy key shown under Source to the repository, then check it.')
        await expect(banner.getByRole('button', { name: 'Check the repository' })).toBeVisible()
        await expect(page.getByRole('button', { name: 'Deploy', exact: true })).toBeDisabled()
        await expect(page.getByRole('button', { name: 'Deploy', exact: true })).toHaveAccessibleDescription(
            "Coresmith hasn't read this repository yet, so the first deploy waits for a check."
        )
    })

    test('a stack the repository cannot run is offered the stack Coresmith read, and using it clears the way to deploy', async ({
        page
    }, testInfo) => {
        await gotoApplication(page, 'bench-01', own(testInfo, 'proposal'))

        const banner = page.getByTestId('repository-check-banner')

        await expect(banner).toContainText("This app's stack can't run this repository.")
        await expect(banner).toContainText(
            "There is no artisan file at the repository's root, so this isn't a Laravel app. Pick Plain PHP, or Symfony if it uses Symfony."
        )
        await expect(banner).toContainText('Coresmith read Plain PHP, PHP 8.5.10 from the repository.')

        await banner.getByRole('button', { name: 'Use Plain PHP, PHP 8.5.10' }).click()

        await expect(toast(page)).toContainText('Saved.')
        await expect(page.getByTestId('repository-check-banner')).toHaveCount(0)
        await expect(page.getByRole('button', { name: 'Deploy', exact: true })).toBeEnabled()
    })

    test('the banner passes axe in light and dark', async ({ page }, testInfo) => {
        for (const theme of ['light', 'dark'] as const) {
            await useTheme(page, theme)
            await gotoApplication(page, 'bench-01', own(testInfo, 'unchecked'))
            await expect(page.getByTestId('repository-check-banner')).toBeVisible()

            expect(await blockingViolations(page)).toEqual([])
        }
    })
})
