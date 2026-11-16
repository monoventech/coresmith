import type { Page } from '@playwright/test'
import {
    blockingViolations,
    dialog,
    expect,
    gotoApplication,
    isMobile,
    own,
    test,
    toast,
    useTheme
} from './support/fixtures'

function card(page: Page) {
    return page.getByTestId('deploy-script')
}

test.describe('the deploy script', () => {
    test.beforeEach(({ page: _page }, testInfo) => {
        test.skip(isMobile(testInfo), 'The deploy script card is hidden on phones, like Environment and Cron jobs')
    })

    test("shows Coresmith's default, takes a script of your own, and goes back to the default", async ({
        page
    }, testInfo) => {
        const app = own(testInfo, 'filed')

        await gotoApplication(page, 'bench-01', app)
        await expect(card(page)).toContainText('The first command that fails stops the deploy')
        await expect(card(page).getByTestId('deploy-script-preview')).toContainText(
            'composer install --no-dev --optimize-autoloader --no-interaction'
        )
        await expect(card(page)).not.toContainText('Your own')

        await card(page).getByRole('button', { name: 'Edit script' }).click()
        await expect(dialog(page)).toContainText(`Deploy script for ${app}`)

        const script = dialog(page).getByRole('textbox', { name: 'Script' })

        await expect(script).toHaveValue(/^composer install/)
        await script.fill('cd apps/web\nbun install --frozen-lockfile\nbun run build')
        await dialog(page).getByRole('button', { name: 'Save script' }).click()

        await expect(toast(page)).toContainText('Saved. The next deploy runs it.')
        await expect(dialog(page)).toHaveCount(0)
        await expect(card(page)).toContainText('Your own')
        await expect(card(page).getByTestId('deploy-script-preview').getByRole('listitem')).toHaveText([
            /cd apps\/web$/,
            /bun install --frozen-lockfile$/,
            /bun run build$/
        ])
        await expect(card(page)).toContainText('Your script decides whether migrations run')

        await card(page).getByRole('button', { name: 'Use the default' }).click()
        await expect(toast(page)).toContainText("Back to Coresmith's default.")
        await expect(card(page)).not.toContainText('Your own')
        await expect(card(page).getByTestId('deploy-script-preview')).toContainText('composer install')
    })

    for (const theme of ['light', 'dark'] as const) {
        test(`the card and its editor are accessible in ${theme}`, async ({ page }, testInfo) => {
            await useTheme(page, theme)
            await gotoApplication(page, 'bench-01', own(testInfo, 'filed'))
            await expect(card(page)).toBeVisible()

            expect(await blockingViolations(page)).toEqual([])

            await card(page).getByRole('button', { name: 'Edit script' }).click()
            await expect(dialog(page)).toBeVisible()

            expect(await blockingViolations(page)).toEqual([])
        })
    }
})
