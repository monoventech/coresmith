import type { Page } from '@playwright/test'
import { expect, gotoApplication, gotoDatabase, gotoServer, gotoServerTab, own, tabs, test } from './support/fixtures'

test.use({ reducedMotion: 'reduce' })

async function firstPageProp(page: Page, keys: string[]): Promise<string> {
    return page.evaluate((path) => {
        const data = JSON.parse(document.querySelector('script[data-page]')?.textContent ?? '{}')

        return String(path.reduce((value, key) => value?.[key], data.props))
    }, keys)
}

test('the address bar reads the names of the server, app and database you opened', async ({ page }, testInfo) => {
    const app = own(testInfo, 'held')
    const database = own(testInfo, 'reveal', '_')

    await gotoServer(page, 'bench-01')
    await expect(page).toHaveURL(/\/servers\/bench-01$/)

    await gotoApplication(page, 'bench-01', app)
    await expect(page).toHaveURL(new RegExp(`/servers/bench-01/applications/${app}$`))

    await gotoDatabase(page, 'bench-01', database)
    await expect(page).toHaveURL(new RegExp(`/servers/bench-01/databases/${database}$`))

    await gotoServerTab(page, 'bench-01', 'Activity')
    await expect(tabs(page, 'Show').getByRole('link', { name: 'Alerts', exact: true })).toHaveAttribute(
        'href',
        /\/servers\/bench-01\/activity\?show=alerts$/
    )
})

test('a deploy is addressed by its number, and its release name still leads there', async ({ page }, testInfo) => {
    const app = own(testInfo, 'held')

    await page.goto(`/servers/bench-01/applications/${app}/deployments/20261112-091400`)

    await expect(page).toHaveURL(new RegExp(`/servers/bench-01/applications/${app}/deployments/\\d+$`))
    await expect(page.getByRole('heading', { level: 1, name: `Deploying ${app}` })).toBeVisible()
})

test('an old address with an id takes you to the readable one', async ({ page }, testInfo) => {
    const app = own(testInfo, 'held')

    await page.goto('/servers/bench-01')
    const server = await firstPageProp(page, ['server', 'id'])

    await page.goto(`/servers/${server}`)
    await expect(page).toHaveURL(/\/servers\/bench-01$/)
    await expect(page.getByRole('heading', { level: 1, name: 'bench-01' })).toBeVisible()

    await page.goto(`/servers/bench-01/applications/${app}`)
    const application = await firstPageProp(page, ['application', 'id'])

    await page.goto(`/servers/${server}/applications/${application}`)
    await expect(page).toHaveURL(new RegExp(`/servers/bench-01/applications/${app}$`))
    await expect(page.getByRole('heading', { level: 1, name: app, exact: true })).toBeVisible()
})
