import type { Page } from '@playwright/test'
import {
    blockingViolations,
    expect,
    expectWayBack,
    gotoApplication,
    isMobile,
    main,
    nav,
    asNarrowed as test,
    useTheme,
    withProps
} from './support/fixtures'

function palette(page: Page) {
    return page.getByRole('dialog', { name: 'Command palette' })
}

async function emptyFleet(page: Page) {
    await withProps(page, /\/dashboard$/, (props) => {
        props.servers = []
        props.shared = []
    })
    await page.goto('/deployments')
    await nav(page).getByRole('link', { name: 'Fleet', exact: true }).click()
    await page.waitForURL(/\/dashboard$/)
}

async function openShared(page: Page, name: string) {
    await page.goto('/dashboard')
    await page.getByTestId('shared-apps').getByRole('link', { name, exact: true }).click()
    await expect(page.getByRole('heading', { level: 1, name })).toBeVisible()
}

test.describe('an admin who sees only what is shared', () => {
    test('an app in a project they were not given names the project without a link', async ({ page }) => {
        await gotoApplication(page, 'forge-01', 'atlas')

        await expect(page.getByTestId('app-project')).toContainText('Atlas › Production')
        await expect(page.getByTestId('app-project').getByRole('link')).toHaveCount(0)
    })

    test('the fleet shows only the shared server, and search finds only what is shared', async ({ page }) => {
        await page.goto('/dashboard')

        await expect(page.getByTestId('server-card')).toHaveCount(1)
        await expect(page.getByTestId('server-card').getByRole('link', { name: 'forge-01', exact: true })).toBeVisible()
        await expect(page.getByTestId('shared-apps')).toContainText('warehouse')
        await expect(page.getByTestId('shared-apps')).toContainText('on anvil-01')

        await expect(page.getByRole('heading', { level: 1 })).toBeVisible()
        await page.keyboard.press('ControlOrMeta+K')
        await expect(palette(page)).toBeVisible()

        await page.keyboard.type('forge')
        await expect(palette(page).getByRole('option', { name: /Open forge-01/ })).toBeVisible()
        await expect(palette(page).getByRole('option', { name: /Open forge-02/ })).toHaveCount(0)

        await page.keyboard.press('ControlOrMeta+A')
        await page.keyboard.type('atlas')
        await expect(palette(page).getByRole('option', { name: 'Open atlas on forge-01' })).toBeVisible()
        await expect(palette(page).getByRole('option', { name: /Open atlas-staging/ })).toHaveCount(0)
    })

    test('on a phone the Apps row opens an app in a project they were not given', async ({ page }, testInfo) => {
        test.skip(!isMobile(testInfo), 'From 640px up the explorer lists these apps itself')

        await page.goto('/dashboard')

        const projects = page.getByTestId('phone-projects')

        await projects.getByRole('button', { name: /^Apps/ }).click()
        await projects.getByRole('link', { name: /^atlas,/ }).click()

        await expect(page.getByRole('heading', { level: 1, name: 'atlas' })).toBeVisible()
    })

    test("an unshared server's address answers with the not-found page", async ({ page }) => {
        await openShared(page, 'warehouse')

        const server = /\/servers\/([A-Za-z0-9]+)\/applications\//.exec(page.url())?.[1]

        expect(server).toBeTruthy()
        expect((await page.goto(`/servers/${server}`))?.status()).toBe(404)
        await expect(page.getByRole('heading', { name: 'anvil-01' })).toHaveCount(0)
    })

    test('the app shared on its own opens and names its server without a link', async ({ page }) => {
        await openShared(page, 'warehouse')

        await expect(page.getByTestId('app-server')).toContainText('on anvil-01')
        await expect(main(page).getByRole('link', { name: 'anvil-01' })).toHaveCount(0)
        await expectWayBack(page, 'Fleet')
    })

    test('with nothing shared, the fleet says so and who can share', async ({ page }) => {
        await emptyFleet(page)

        await expect(main(page)).toContainText('Nothing is shared with you yet.')
        await expect(main(page)).toContainText('Ask an owner or admin of your team to share a server or app with you.')
        await expect(page.getByTestId('server-card')).toHaveCount(0)
    })

    test('deployments and releases list only shared apps', async ({ page }) => {
        await page.goto('/deployments')

        await expect(page.getByTestId('deployment-row').filter({ hasText: 'relay' }).first()).toBeVisible()
        await expect(page.getByTestId('deployment-row').filter({ hasText: 'hungry' })).toHaveCount(0)

        await page.goto('/releases')

        const app = (name: string) =>
            page.getByTestId('releases-app').filter({ has: page.getByText(name, { exact: true }) })

        await expect(app('warehouse')).toBeVisible()
        await expect(app('relay')).toBeVisible()
        await expect(app('hungry')).toHaveCount(0)
        await expect(app('atlas-staging')).toHaveCount(0)
    })
})

test.describe('accessibility of what a narrowed admin sees', () => {
    test.use({ reducedMotion: 'reduce' })

    for (const theme of ['light', 'dark'] as const) {
        test(`the fleet and an app shared on its own have no serious or critical violations in ${theme}`, async ({
            page
        }) => {
            await useTheme(page, theme)
            await page.goto('/dashboard')
            await expect(page.getByTestId('shared-apps')).toBeVisible()

            expect(await blockingViolations(page), `fleet in ${theme}`).toEqual([])

            await page.getByTestId('shared-apps').getByRole('link', { name: 'warehouse', exact: true }).click()
            await expect(page.getByRole('heading', { level: 1, name: 'warehouse' })).toBeVisible()

            expect(await blockingViolations(page), `warehouse in ${theme}`).toEqual([])
        })

        test(`the fleet with nothing shared has no serious or critical violations in ${theme}`, async ({ page }) => {
            await useTheme(page, theme)
            await emptyFleet(page)
            await expect(main(page)).toContainText('Nothing is shared with you yet.')

            expect(await blockingViolations(page), `empty fleet in ${theme}`).toEqual([])
        })
    }
})
