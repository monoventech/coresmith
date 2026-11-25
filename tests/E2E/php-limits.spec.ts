import type { Page, TestInfo } from '@playwright/test'
import {
    asMember,
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
    return page.getByTestId('php-limits')
}

async function openApp(page: Page, testInfo: TestInfo) {
    await gotoApplication(page, 'bench-01', own(testInfo, 'php'))
}

async function change(page: Page, [uploads, memory, seconds, reload]: [string, string, string, string]) {
    await card(page).getByRole('button', { name: 'Change' }).click()
    await expect(dialog(page).getByRole('heading', { name: 'Change PHP limits' })).toBeVisible()
    await dialog(page).getByLabel('Uploads up to').selectOption({ label: uploads })
    await dialog(page).getByLabel('Memory per process').selectOption({ label: memory })
    await dialog(page).getByLabel('Web requests stop after').selectOption({ label: seconds })
    await dialog(page).getByRole('radio', { name: reload }).click()
    await dialog(page).getByRole('button', { name: 'Save' }).click()
    await expect(toast(page)).toContainText('Saved. PHP on bench-01 picks these up in a few seconds, without a deploy.')
    await expect(dialog(page)).toHaveCount(0)
}

test.describe('PHP limits on the app page', () => {
    test('a PHP app shows its limits, and Change saves new ones without a deploy', async ({ page }, testInfo) => {
        test.skip(isMobile(testInfo), 'The PHP card is hidden on phones, like Environment and Cron jobs')

        await openApp(page, testInfo)

        const values = card(page).locator('dd')

        await expect(card(page).locator('dt')).toHaveText([
            'Uploads up to',
            'Memory',
            'Web requests stop after',
            'New code loads'
        ])
        await expect(values).toHaveText(['64 MB per request', '256 MB per process', '60 seconds', 'At each deploy'])

        await change(page, ['128 MB per request', '512 MB', '2 minutes', 'Every 2 seconds'])
        await expect(values).toHaveText(['128 MB per request', '512 MB per process', '2 minutes', 'Every 2 seconds'])

        await change(page, ['64 MB per request', '256 MB', '60 seconds', 'At each deploy, which is faster'])
        await expect(values).toHaveText(['64 MB per request', '256 MB per process', '60 seconds', 'At each deploy'])
    })

    test('memory choices stop at what the app size allows, and the dialog says so', async ({ page }, testInfo) => {
        test.skip(isMobile(testInfo), 'The PHP card is hidden on phones, like Environment and Cron jobs')

        await openApp(page, testInfo)
        await card(page).getByRole('button', { name: 'Change' }).click()

        const memory = dialog(page).getByLabel('Memory per process')

        await expect(memory.locator('option')).toHaveText(['64 MB', '128 MB', '256 MB', '512 MB'])
        await expect(dialog(page)).toContainText('The Medium size allows up to 512 MB. A larger size allows more.')
        await dialog(page).getByRole('button', { name: 'Cancel' }).click()
        await expect(dialog(page)).toHaveCount(0)
    })

    test('an app that does not run PHP has no PHP card', async ({ page }) => {
        await gotoApplication(page, 'forge-01', 'storefront')

        await expect(page.getByRole('heading', { name: 'Processes', exact: true })).toBeVisible()
        await expect(card(page)).toHaveCount(0)
    })

    test('phones leave the card out, as the phone prototype does', async ({ page }, testInfo) => {
        test.skip(!isMobile(testInfo), 'Desktop shows the card')

        await openApp(page, testInfo)

        await expect(page.getByRole('heading', { name: 'Processes', exact: true })).toBeVisible()
        await expect(card(page)).toBeHidden()
    })

    test('the card and its dialog pass axe in light and dark', async ({ page }, testInfo) => {
        test.skip(isMobile(testInfo), 'The PHP card is hidden on phones, like Environment and Cron jobs')

        for (const theme of ['light', 'dark'] as const) {
            await useTheme(page, theme)
            await openApp(page, testInfo)
            await expect(card(page)).toBeVisible()

            expect(await blockingViolations(page)).toEqual([])

            await card(page).getByRole('button', { name: 'Change' }).click()
            await expect(dialog(page)).toBeVisible()
            expect(await blockingViolations(page)).toEqual([])
            await dialog(page).getByRole('button', { name: 'Cancel' }).click()
        }
    })
})

asMember('a member sees the PHP limits but cannot change them', async ({ page }, testInfo) => {
    test.skip(isMobile(testInfo), 'The PHP card is hidden on phones, like Environment and Cron jobs')

    await openApp(page, testInfo)

    await expect(card(page).locator('dd').first()).toHaveText('64 MB per request')
    await expect(card(page).getByRole('button', { name: 'Change' })).toBeDisabled()
})
