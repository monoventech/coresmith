import { expect, gotoApplication, gotoApplications, settled, test } from './support/fixtures'

test.describe('Application lifecycle', () => {
    test('an application can be created end to end', async ({ page }) => {
        const name = `Shop ${Date.now()}`

        await gotoApplications(page)
        await page.getByRole('link', { name: /New application|Create an application/ }).click()

        await page.getByLabel('Name').fill(name)
        await page.getByLabel('Type').selectOption('js')
        await page.getByLabel('Domain').fill(`shop-${Date.now()}.example.com`)
        await page.getByRole('button', { name: 'Create application' }).click()

        await settled(page, name)
        await expect(page.getByRole('heading', { name })).toBeVisible()
    })

    test('a malformed domain is refused', async ({ page }) => {
        await gotoApplications(page)
        await page.getByRole('link', { name: /New application|Create an application/ }).click()

        await page.getByLabel('Name').fill('Bad Domain App')
        await page.getByLabel('Domain').fill('evil.com, other.com')
        await page.getByRole('button', { name: 'Create application' }).click()

        await settled(page, 'valid domain')
    })

    test('the port block and stack are shown on the detail page', async ({ page }) => {
        await gotoApplication(page)

        await expect(page.getByText('8000–8009')).toBeVisible()
        await expect(page.getByText('laravel')).toBeVisible()
    })

    test('deploying queues work and reports it', async ({ page }) => {
        await gotoApplication(page)
        await page.getByRole('button', { name: 'Deploy', exact: true }).click()

        await settled(page, 'Deployment queued')
    })

    test('rolling back reports that it started', async ({ page }) => {
        await gotoApplication(page)
        await page.getByRole('button', { name: 'Roll back' }).click()

        await settled(page, 'Rolling back')
    })

    test('environment variables can be saved and are shown again', async ({ page }) => {
        await gotoApplication(page)
        await page.getByRole('link', { name: 'Environment' }).click()

        await expect(page.getByRole('heading', { name: 'Environment' })).toBeVisible()

        const value = `APP_ENV=production\nRUN_ID=${Date.now()}`
        await page.getByRole('textbox').fill(value)
        await page.getByRole('button', { name: 'Save environment' }).click()

        await settled(page, 'Environment saved')

        await page.reload()
        await expect(page.getByRole('textbox')).toHaveValue(/RUN_ID=/)
    })

    test('the environment page warns that a deploy is needed', async ({ page }) => {
        await gotoApplication(page)
        await page.getByRole('link', { name: 'Environment' }).click()

        await expect(page.getByText(/Deploy afterwards to apply/i)).toBeVisible()
    })
})
