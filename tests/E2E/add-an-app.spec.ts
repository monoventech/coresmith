import type { Page } from '@playwright/test'
import {
    asDeveloper,
    asNarrowed,
    asPartlyShared,
    blockingViolations,
    dialog,
    expect,
    gotoServer,
    gotoServerTab,
    main,
    test,
    useTheme
} from './support/fixtures'

function servers(page: Page) {
    return page.getByRole('list', { name: 'Servers' })
}

function fleetAddApp(page: Page) {
    return page.getByTestId('fleet-actions').getByRole('link', { name: 'Add an app' })
}

function palette(page: Page) {
    return page.getByRole('dialog', { name: 'Command palette' })
}

async function runCommand(page: Page, name: string) {
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible()
    await page.keyboard.press('ControlOrMeta+K')
    await expect(palette(page)).toBeVisible()
    await page.keyboard.type(name)
    await palette(page)
        .getByRole('option', { name: new RegExp(`^${name}`) })
        .click()
}

test.describe('adding an app', () => {
    test('the fleet offers it first, then asks which server runs it', async ({ page }) => {
        await page.goto('/dashboard')
        await fleetAddApp(page).click()

        await expect(main(page)).toContainText('Pick the server that runs it.')
        await expect(servers(page).getByRole('listitem').filter({ hasText: 'bench-01' })).toContainText(/\d+ apps/)
        await expect(servers(page)).not.toContainText('empty-server')

        await servers(page)
            .getByRole('link', { name: /bench-01/ })
            .click()

        await expect(main(page)).toContainText('It runs on bench-01, walled off from the other apps there.')
        await expect(page.getByRole('heading', { level: 1, name: 'Add an app' })).toBeVisible()
    })

    test('a server with apps offers Add an app at the top of every tab', async ({ page }) => {
        await gotoServer(page, 'bench-01')
        await expect(page.getByTestId('server-primary')).toHaveText('Add an app')

        await gotoServerTab(page, 'bench-01', 'Activity')
        await expect(page.getByTestId('server-primary')).toHaveText('Add an app')
        await page.getByTestId('server-primary').click()

        await expect(main(page)).toContainText('It runs on bench-01, walled off from the other apps there.')
    })

    test('a server without apps offers it once, where the apps would be', async ({ page }) => {
        await gotoServer(page, 'full-01')

        await expect(page.getByTestId('server-primary')).toHaveCount(0)
        await expect(main(page)).toContainText('Nothing deployed here yet.')
        await expect(main(page).getByRole('link', { name: 'Add an app' })).toHaveCount(1)
    })

    test('the command palette adds an app and creates a project', async ({ page }) => {
        await page.goto('/dashboard')
        await runCommand(page, 'Add an app')
        await expect(main(page)).toContainText('Pick the server that runs it.')

        await runCommand(page, 'Create a project')
        await expect(dialog(page).getByRole('heading', { name: 'Create a project' })).toBeVisible()
        await expect(dialog(page).getByLabel('Project name')).toBeFocused()
        await expect(page).not.toHaveURL(/create=/)
    })

    for (const theme of ['light', 'dark'] as const) {
        test(`the server list is accessible in ${theme}`, async ({ page }) => {
            await useTheme(page, theme)
            await page.goto('/dashboard')
            await fleetAddApp(page).click()
            await expect(servers(page)).toBeVisible()

            expect(await blockingViolations(page)).toEqual([])
        })
    }
})

test.describe('adding an app from the explorer', () => {
    test.skip(
        ({ isMobile }) => isMobile,
        'The rail and explorer start at 640px; phones use the bottom tabs and the fleet lists'
    )

    test('the Projects and Apps headings create a project and add an app', async ({ page }) => {
        const explorer = page.getByRole('complementary', { name: 'Explorer' })

        await page.goto('/dashboard')
        await explorer.getByRole('link', { name: 'Create a project' }).click()
        await expect(dialog(page).getByRole('heading', { name: 'Create a project' })).toBeVisible()
        await page.keyboard.press('Escape')
        await expect(dialog(page)).toHaveCount(0)

        await explorer.getByRole('link', { name: 'Add an app' }).click()
        await expect(main(page)).toContainText('Pick the server that runs it.')
    })
})

asNarrowed.describe('adding an app with one server to run it', () => {
    asNarrowed('Add an app opens the form for that server straight away', async ({ page }) => {
        await page.goto('/dashboard')
        await fleetAddApp(page).click()

        await expect(main(page)).toContainText('It runs on forge-01, walled off from the other apps there.')
    })
})

asDeveloper.describe('adding an app as a developer', () => {
    asDeveloper('only the servers the developer can see are offered', async ({ page }) => {
        await page.goto('/dashboard')
        await fleetAddApp(page).click()

        await expect(servers(page)).toContainText('forge-02')
        await expect(servers(page)).toContainText('lena-01')
        await expect(servers(page)).not.toContainText('bench-01')
    })
})

asPartlyShared.describe('adding an app with no server to run it', () => {
    asPartlyShared('the page says why and offers to provision one', async ({ page }) => {
        await page.goto('/apps/new')

        await expect(page.getByRole('heading', { level: 1, name: 'Add an app' })).toBeVisible()
        await expect(main(page)).toContainText('No server can take an app yet.')
        await expect(main(page).getByRole('link', { name: 'Provision a server' })).toBeVisible()
    })
})
