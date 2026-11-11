import type { Page } from '@playwright/test'
import { dialog, expect, nav, own, test, toast } from './support/fixtures'

function card(page: Page, name: string) {
    return page.getByTestId('project-card').filter({ has: page.getByRole('link', { name, exact: true }) })
}

function environment(page: Page, name: string) {
    return page.getByTestId('environment').filter({ has: page.getByRole('heading', { level: 2, name, exact: true }) })
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
        await expect(card(page, 'Atlas')).toContainText('2 environments · 2 apps · 1 database')
        await expect(card(page, 'Storefront').getByTestId('project-environment')).toHaveText([
            'Production 1',
            'Staging 1'
        ])
        await expect(page.getByTestId('no-project')).toContainText('ledger')
        await expect(nav(page).getByRole('link', { name: 'Fleet', exact: true })).toHaveAttribute(
            'aria-current',
            'page'
        )

        await fleetViews(page).getByRole('link', { name: 'Servers', exact: true }).click()
        await page.waitForURL(/\/dashboard$/)
        await expect(page.getByTestId('server-card').first()).toBeVisible()
    })

    test('a project page lists each environment with what is in it', async ({ page }) => {
        await page.goto('/projects')
        await card(page, 'Storefront').getByRole('link', { name: 'Storefront', exact: true }).click()

        await expect(page.getByRole('heading', { level: 1, name: 'Storefront' })).toBeVisible()
        await expect(
            environment(page, 'Production').getByRole('link', { name: 'storefront', exact: true })
        ).toBeVisible()
        await expect(
            environment(page, 'Staging').getByRole('link', { name: 'storefront-staging', exact: true })
        ).toBeVisible()
        await expect(environment(page, 'Production')).toContainText('Recent deploys')
        await expect(nav(page).getByRole('link', { name: 'Fleet', exact: true })).toHaveAttribute(
            'aria-current',
            'page'
        )

        await expect(page.getByTestId('back-link')).toHaveText('← Projects')
        await page.getByTestId('back-link').click()
        await page.waitForURL(/\/projects$/)
    })

    test('an app page names the project it is in', async ({ page }) => {
        await page.goto('/projects')
        await card(page, 'Atlas').getByRole('link', { name: 'Atlas', exact: true }).click()
        await environment(page, 'Production').getByRole('link', { name: 'atlas', exact: true }).click()

        await expect(page.getByRole('heading', { level: 1, name: 'atlas' })).toBeVisible()
        await page.getByTestId('app-project').getByRole('link', { name: 'Atlas › Production' }).click()
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
        await expect(environment(page, 'Production')).toContainText('Nothing in Production yet.')

        await page.getByRole('button', { name: 'Add environment' }).click()
        await dialog(page).getByLabel('Environment name').fill('Preview')
        await dialog(page).getByRole('button', { name: 'Add environment' }).click()
        await expect(environment(page, 'Preview')).toBeVisible()

        await environment(page, 'Production').getByRole('button', { name: 'Add apps or databases' }).click()
        await expect(dialog(page)).toContainText('Anyone this environment is shared with will see what you add.')
        await dialog(page).getByRole('checkbox', { name: app, exact: true }).click()
        await dialog(page).getByRole('button', { name: 'Add to Production' }).click()

        await expect(toast(page)).toContainText('Added to Production.')
        await expect(environment(page, 'Production').getByRole('link', { name: app, exact: true })).toBeVisible()

        await environment(page, 'Production')
            .getByRole('button', { name: `Take ${app} out of Production` })
            .click()
        await expect(toast(page)).toContainText(`Took ${app} out of ${name}.`)
        await expect(environment(page, 'Production')).toContainText('Nothing in Production yet.')

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
})
