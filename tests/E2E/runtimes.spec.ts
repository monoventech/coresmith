import type { Page, TestInfo } from '@playwright/test'
import {
    blockingViolations,
    dialog,
    expect,
    gotoApplication,
    gotoServer,
    own,
    test,
    toast,
    useTheme
} from './support/fixtures'

test.use({ reducedMotion: 'reduce' })

function installed(page: Page, label: string) {
    return page.getByTestId('runtimes-card').getByTestId('runtime-version').filter({ hasText: label })
}

async function gotoRuntime(page: Page, testInfo: TestInfo, app: string) {
    await gotoApplication(page, own(testInfo, 'runtimes'), own(testInfo, app))

    return page.getByTestId('runtime-card').getByTestId('runtime-row').first()
}

test.describe("a server's runtimes", () => {
    test('the server lists each installed version with the apps on it, and offers Remove only where nothing needs it', async ({
        page
    }, testInfo) => {
        const server = own(testInfo, 'runtimes')

        await gotoServer(page, server)

        const used = installed(page, 'PHP 8.4.1')
        const unused = installed(page, 'PHP 8.3.33')

        await expect(page.getByRole('heading', { level: 2, name: `Runtimes on ${server}` })).toBeVisible()
        await expect(used.getByRole('link', { name: own(testInfo, 'rt'), exact: true })).toBeVisible()
        await expect(used.getByRole('link', { name: own(testInfo, 'rt-pin'), exact: true })).toBeVisible()
        await expect(used.getByRole('button', { name: /^Remove/ })).toHaveCount(0)
        await expect(unused).toContainText('No app uses it.')
        await expect(unused.getByRole('button', { name: 'Remove PHP 8.3.33' })).toBeVisible()
        await expect(page.getByRole('button', { name: /^Update to/ })).toHaveCount(0)
    })

    test('removing a version nothing uses asks first, passes axe in light, and says Coresmith is on it', async ({
        page
    }, testInfo) => {
        const server = own(testInfo, 'runtimes')

        await useTheme(page, 'light')
        await gotoServer(page, server)
        await installed(page, 'PHP 8.3.33').getByRole('button', { name: 'Remove PHP 8.3.33' }).click()

        await expect(dialog(page).getByRole('heading', { name: `Remove PHP 8.3.33 from ${server}?` })).toBeVisible()
        expect(await blockingViolations(page)).toEqual([])

        await dialog(page).getByRole('button', { name: 'Remove', exact: true }).click()

        await expect(toast(page)).toContainText(`Removing PHP 8.3.33 from ${server}.`)
        await expect(dialog(page)).toHaveCount(0)
    })
})

test.describe("an app's runtime", () => {
    test('says where its version came from and offers the newest patch for this app alone, in light', async ({
        page
    }, testInfo) => {
        const app = own(testInfo, 'rt')

        await useTheme(page, 'light')
        const row = await gotoRuntime(page, testInfo, 'rt')

        await expect(row).toContainText('PHP 8.4.1')
        await expect(row).toContainText('From composer.json, which asks for ^8.4')
        await expect(row.getByTestId('runtime-newer')).toContainText(/PHP\s+8\.4\.\d+\s+is the newest in this line/)
        await expect(row.getByTestId('runtime-newer')).toContainText(
            new RegExp(`Updating installs 8\\.4\\.\\d+ beside 8\\.4\\.1 and redeploys\\s+${app}\\.`)
        )

        await row.getByRole('button', { name: /^Update to 8\.4\.\d+$/ }).click()

        await expect(dialog(page).getByRole('heading', { name: /^Update PHP to 8\.4\.\d+\?$/ })).toBeVisible()
        await expect(dialog(page)).toContainText(`then deploys ${app} on it.`)
        expect(await blockingViolations(page)).toEqual([])

        await dialog(page).getByRole('button', { name: 'Cancel' }).click()
        await expect(dialog(page)).toHaveCount(0)
    })

    test('an exact version in the repository is named as the place to change it, with no update offered', async ({
        page
    }, testInfo) => {
        const row = await gotoRuntime(page, testInfo, 'rt-pin')

        await expect(row).toContainText('From .tool-versions, which asks for 8.4.1')
        await expect(row.getByTestId('runtime-newer')).toContainText(
            'Its .tool-versions asks for exactly 8.4.1. Change it there to move up.'
        )
        await expect(row.getByRole('button', { name: /^Update to/ })).toHaveCount(0)
    })

    test('updating installs the newest patch and the card says so until it is in, in dark', async ({
        page
    }, testInfo) => {
        const app = own(testInfo, 'rt')

        await useTheme(page, 'dark')
        const row = await gotoRuntime(page, testInfo, 'rt')

        await row.getByRole('button', { name: /^Update to 8\.4\.\d+$/ }).click()
        expect(await blockingViolations(page)).toEqual([])
        await dialog(page).getByRole('button', { name: 'Update and deploy' }).click()

        await expect(toast(page)).toContainText(
            new RegExp(`Installing PHP 8\\.4\\.\\d+\\. ${app} deploys once it's in\\.`)
        )
        await expect(row.getByRole('status')).toContainText(
            new RegExp(`Installing PHP 8\\.4\\.\\d+\\.\\s+${app}\\s+deploys on it\\s+once it's in\\.`)
        )
        await expect(row.getByRole('button', { name: /^Update to/ })).toHaveCount(0)
        expect(await blockingViolations(page)).toEqual([])
    })
})
