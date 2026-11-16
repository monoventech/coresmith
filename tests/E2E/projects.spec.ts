import type { Page } from '@playwright/test'
import {
    blockingViolations,
    dialog,
    expect,
    expectWayBack,
    goWayBack,
    nav,
    own,
    test,
    toast,
    useTheme
} from './support/fixtures'

function card(page: Page, name: string) {
    return page.getByTestId('project-card').filter({ has: page.getByRole('link', { name, exact: true }) })
}

function appRow(page: Page, name: string) {
    return page.getByTestId('project-app').filter({ has: page.getByRole('link', { name, exact: true }) })
}

function fleetViews(page: Page) {
    return page.getByRole('navigation', { name: 'Fleet views' })
}

test.describe('projects', () => {
    test('the fleet switches between its servers and its projects', async ({ page }) => {
        await page.goto('/dashboard')
        await fleetViews(page).getByRole('link', { name: 'Projects', exact: true }).click()
        await page.waitForURL(/\/projects$/)

        await expect(page.getByRole('heading', { level: 1 })).toHaveText('Your fleet')
        await expect(card(page, 'Atlas')).toContainText(/\d+ apps? · 1 database/)
        const storefront = card(page, 'Storefront').getByTestId('project-card-app')

        await expect(storefront.filter({ hasText: /^\s*storefront production\s*$/ })).toHaveCount(1)
        await expect(storefront.filter({ hasText: /^\s*storefront-staging staging\s*$/ })).toHaveCount(1)
        await expect(page.getByTestId('no-project')).toContainText('ledger')
        await expect(nav(page).getByRole('link', { name: 'Fleet', exact: true })).toHaveAttribute(
            'aria-current',
            'page'
        )

        await fleetViews(page).getByRole('link', { name: 'Servers', exact: true }).click()
        await page.waitForURL(/\/dashboard$/)
        await expect(page.getByTestId('server-card').first()).toBeVisible()
    })

    test('a project page shows each app with its tags and last deploy, then deploys, servers and who can see it', async ({
        page
    }) => {
        await page.goto('/projects')
        await card(page, 'Storefront').getByRole('link', { name: 'Storefront', exact: true }).click()

        await expect(page.getByRole('heading', { level: 1, name: 'Storefront' })).toBeVisible()
        await expect(page.getByText(/^\d+ apps · on 2 servers$/)).toBeVisible()
        await expect(appRow(page, 'storefront').getByTestId('app-tags')).toHaveText('production')
        await expect(appRow(page, 'storefront-staging').getByTestId('app-tags')).toHaveText('staging')
        await expect(appRow(page, 'storefront').getByTestId('last-deploy')).not.toHaveText('Not deployed yet')
        await expect(appRow(page, 'storefront').getByRole('button', { name: 'Deploy storefront' })).toBeVisible()
        await expect(page.getByTestId('project-deploys')).toContainText('Recent deploys')
        await expect(page.getByTestId('project-servers').getByRole('link', { name: 'forge-01' })).toBeVisible()
        await expect(
            page.getByTestId('project-people').getByRole('listitem').filter({ hasText: 'Ines Duarte' })
        ).toContainText('Shared in part')
        await expect(nav(page).getByRole('link', { name: 'Fleet', exact: true })).toHaveAttribute(
            'aria-current',
            'page'
        )

        await expectWayBack(page, 'Projects')
        await goWayBack(page, 'Projects')
        await page.waitForURL(/\/projects$/)
    })

    test('an app page names the project it is in', async ({ page }) => {
        await page.goto('/projects')
        await card(page, 'Atlas').getByRole('link', { name: 'Atlas', exact: true }).click()
        await appRow(page, 'atlas').getByRole('link', { name: 'atlas', exact: true }).click()

        await expect(page.getByRole('heading', { level: 1, name: 'atlas' })).toBeVisible()
        await page.getByTestId('app-project').getByRole('link', { name: 'Atlas', exact: true }).click()
        await expect(page.getByRole('heading', { level: 1, name: 'Atlas' })).toBeVisible()
    })

    test('a project is created, filled, emptied, renamed and deleted', async ({ page }, testInfo) => {
        const name = own(testInfo, 'Launch', ' ')
        const app = own(testInfo, 'filed')

        await page.goto('/projects')
        await page.getByRole('button', { name: 'Create a project' }).click()
        await dialog(page).getByLabel('Project name').fill(name)
        await dialog(page).getByRole('button', { name: 'Create project' }).click()

        await expect(page.getByRole('heading', { level: 1, name })).toBeVisible()
        await expect(toast(page)).toContainText(`Created ${name}.`)
        await expect(page.getByText(`Nothing in ${name} yet.`)).toBeVisible()

        await page.getByRole('button', { name: 'Add existing' }).click()
        await expect(dialog(page)).toContainText(`Anyone ${name} is shared with will see what you add.`)
        await dialog(page).getByRole('checkbox', { name: app, exact: true }).click()
        await dialog(page)
            .getByRole('button', { name: `Add to ${name}` })
            .click()

        await expect(toast(page)).toContainText(`Added to ${name}.`)
        await expect(appRow(page, app)).toBeVisible()

        await appRow(page, app)
            .getByRole('button', { name: `Take ${app} out of ${name}` })
            .click()
        await expect(toast(page)).toContainText(`Took ${app} out of ${name}.`)
        await expect(page.getByText(`Nothing in ${name} yet.`)).toBeVisible()

        await page.getByRole('button', { name: 'Rename', exact: true }).click()
        await dialog(page).getByLabel('Project name').fill(`${name} app`)
        await dialog(page).getByRole('button', { name: 'Save' }).click()
        await expect(page.getByRole('heading', { level: 1, name: `${name} app` })).toBeVisible()

        await page.getByRole('button', { name: 'Delete', exact: true }).click()
        await dialog(page)
            .getByRole('button', { name: `Delete ${name} app` })
            .click()

        await page.waitForURL(/\/projects$/)
        await expect(toast(page)).toContainText(`Deleted ${name} app.`)
        await expect(card(page, `${name} app`)).toHaveCount(0)
    })

    test('a duplicate project name says what to do', async ({ page }) => {
        await page.goto('/projects')
        await page.getByRole('button', { name: 'Create a project' }).click()
        await dialog(page).getByLabel('Project name').fill('storefront')
        await dialog(page).getByRole('button', { name: 'Create project' }).click()

        await expect(dialog(page)).toContainText("There's already a project called Storefront.")
    })

    for (const theme of ['light', 'dark'] as const) {
        test(`the project page is accessible in ${theme}`, async ({ page }) => {
            await useTheme(page, theme)
            await page.goto('/projects')
            await card(page, 'Storefront').getByRole('link', { name: 'Storefront', exact: true }).click()
            await expect(page.getByRole('heading', { level: 1, name: 'Storefront' })).toBeVisible()

            expect(await blockingViolations(page)).toEqual([])
        })
    }
})

test.describe('a team without projects', () => {
    test.afterEach(async ({ page }) => {
        await page.goto('/monoven/dashboard')
    })

    test('still lists the apps outside every project', async ({ page }) => {
        await page.goto('/acme/projects')

        await expect(page.getByText('No projects yet.')).toBeVisible()
        await page.getByTestId('no-project').getByRole('link', { name: 'acme-portal', exact: true }).click()

        await expect(page.getByRole('heading', { level: 1, name: 'acme-portal' })).toBeVisible()
    })
})
