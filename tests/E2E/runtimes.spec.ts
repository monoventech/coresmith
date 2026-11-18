import type { Page, TestInfo } from '@playwright/test'
import { blockingViolations, dialog, expect, gotoServer, own, test, toast, useTheme } from './support/fixtures'

test.use({ reducedMotion: 'reduce' })

async function openUpdate(page: Page, testInfo: TestInfo) {
    await gotoServer(page, own(testInfo, 'runtimes'))

    const row = page.getByTestId('runtimes-card').getByTestId('runtime-row').filter({ hasText: 'PHP 8.4.1' })

    await row.getByRole('button', { name: /^Update to 8\.4\.\d+$/ }).click()
    await expect(dialog(page).getByRole('heading', { name: /^Update PHP 8\.4\.1 to 8\.4\.\d+\?$/ })).toBeVisible()

    return row
}

test.describe("a server's runtimes", () => {
    test('the server lists each runtime version with the apps on it and the newest patch', async ({
        page
    }, testInfo) => {
        await gotoServer(page, own(testInfo, 'runtimes'))

        const card = page.getByTestId('runtimes-card')
        const row = card.getByTestId('runtime-row').filter({ hasText: 'PHP 8.4.1' })

        await expect(card.getByRole('heading', { level: 2, name: 'Runtimes' })).toBeVisible()
        await expect(row).toContainText('Used by 2 apps:')
        await expect(row.getByRole('link', { name: own(testInfo, 'rt'), exact: true })).toBeVisible()
        await expect(row.getByRole('link', { name: own(testInfo, 'rt-pin'), exact: true })).toBeVisible()
        await expect(row.getByRole('button', { name: /^Update to 8\.4\.\d+$/ })).toBeVisible()
    })

    test('the update dialog ticks the apps that can move, explains a pinned one, and passes axe in light', async ({
        page
    }, testInfo) => {
        await useTheme(page, 'light')
        await openUpdate(page, testInfo)

        await expect(dialog(page).getByRole('checkbox', { name: own(testInfo, 'rt'), exact: true })).toBeChecked()
        await expect(dialog(page).getByRole('checkbox', { name: own(testInfo, 'rt-pin'), exact: true })).toBeDisabled()
        await expect(dialog(page)).toContainText(
            'Stays on 8.4.1, since its .tool-versions asks for exactly that version.'
        )
        await expect(dialog(page)).toContainText('A patch rarely changes how an app behaves, but it can break one.')
        expect(await blockingViolations(page)).toEqual([])

        await dialog(page).getByRole('button', { name: 'Cancel' }).click()
        await expect(dialog(page)).toHaveCount(0)
    })

    test('updating installs the newest patch and the card says so until it is in, in dark', async ({
        page
    }, testInfo) => {
        await useTheme(page, 'dark')
        const row = await openUpdate(page, testInfo)

        expect(await blockingViolations(page)).toEqual([])

        await dialog(page).getByRole('button', { name: 'Update and deploy' }).click()

        await expect(toast(page)).toContainText(/Installing PHP 8\.4\.\d+\. The apps deploy once it's in\./)
        await expect(page.getByTestId('runtime-update')).toContainText(
            /Installing PHP 8\.4\.\d+\. The apps deploy again once it's in\./
        )
        await expect(row.getByRole('button', { name: /^Update to/ })).toBeDisabled()
        expect(await blockingViolations(page)).toEqual([])
    })
})
