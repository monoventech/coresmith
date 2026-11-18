import type { Page } from '@playwright/test'
import {
    blockingViolations,
    dialog,
    expect,
    gotoApplication,
    isMobile,
    main,
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

    test('migrations can be turned off and on, and the default script follows', async ({ page }, testInfo) => {
        await gotoApplication(page, 'bench-01', own(testInfo, 'migrate'))

        const toggle = card(page).getByTestId('toggle-run-migrations')
        const preview = card(page).getByTestId('deploy-script-preview')

        await expect(toggle).toContainText(
            'Each deploy runs php artisan migrate --force before the new release goes live.'
        )
        await expect(preview).toContainText('php artisan migrate --force')

        await toggle.getByRole('switch', { name: 'Run migrations' }).click()

        await expect(toast(page)).toContainText('Saved.')
        await expect(toggle).toContainText(
            'Deploys skip php artisan migrate --force. Run it yourself when the schema changes.'
        )
        await expect(preview).not.toContainText('migrate')

        await toggle.getByRole('switch', { name: 'Run migrations' }).click()

        await expect(toggle).toContainText(
            'Each deploy runs php artisan migrate --force before the new release goes live.'
        )
        await expect(preview).toContainText('php artisan migrate --force')
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

test.describe('a deploy whose own script failed', () => {
    test('shows the Deploy script step that failed and offers Edit script, which opens the script', async ({
        page
    }) => {
        await gotoApplication(page, 'bench-01', 'scripted')
        await page.getByTestId('failed-banner').getByRole('link', { name: 'Read the log' }).click()

        await expect(page.getByRole('heading', { level: 1, name: 'Deploying scripted' })).toBeVisible()
        await expect(page.getByTestId('failure-reason')).toContainText(
            'Your deploy script failed, so nothing changed. It stopped with exit code 2.'
        )
        await expect(main(page).getByRole('link', { name: 'Edit setup' })).toHaveCount(0)

        await main(page).getByRole('link', { name: 'Edit script' }).click()

        await expect(page.getByRole('heading', { level: 1, name: 'scripted', exact: true })).toBeVisible()
        await expect(dialog(page).getByRole('heading', { name: 'Deploy script for scripted' })).toBeVisible()
        await expect(dialog(page).getByLabel('Script')).toHaveValue('bun install --frozen-lockfile\nbun run check\n')
        await expect(page).not.toHaveURL(/script=edit/)
    })
})
