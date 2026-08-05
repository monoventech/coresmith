import { expect, gotoDatabases, settled, test } from './support/fixtures'

test.describe('Databases', () => {
    test('seeded databases are listed', async ({ page }) => {
        await gotoDatabases(page)

        await expect(page.getByRole('heading', { name: 'Databases' })).toBeVisible()
        await expect(page.getByRole('link', { name: 'storefront_production' })).toBeVisible()
    })

    test('an empty server shows the empty state', async ({ page }) => {
        await gotoDatabases(page, 'empty-server')

        await expect(page.getByText('No databases yet')).toBeVisible()
    })

    test('a database can be created', async ({ page }) => {
        const name = `shop_${Date.now()}`

        await gotoDatabases(page)
        await page.getByRole('link', { name: /New database|Create a database/ }).click()

        await page.getByLabel('Database name').fill(name)
        await page.getByLabel('Database user').fill(`${name}_user`)
        await page.getByRole('button', { name: 'Create database' }).click()

        await settled(page, 'queued for creation')
        await expect(page.getByRole('link', { name })).toBeVisible()
    })

    test('a name carrying sql metacharacters is refused', async ({ page }) => {
        await gotoDatabases(page)
        await page.getByRole('link', { name: /New database|Create a database/ }).click()

        await page.getByLabel('Database name').fill('shop`; DROP DATABASE mysql; --')
        await page.getByLabel('Database user').fill('shop_user')
        await page.getByRole('button', { name: 'Create database' }).click()

        await settled(page, 'letters, digits and underscores')
    })

    test('the detail page shows the connection but never a password', async ({ page }) => {
        await gotoDatabases(page)
        await page.getByRole('link', { name: 'storefront_production' }).click()

        await expect(page.getByRole('heading', { name: 'Connection' })).toBeVisible()
        await expect(page.getByText('Localhost only')).toBeVisible()

        const html = await page.content()
        expect(html).not.toContain('db_password')
    })

    test('a backup can be started', async ({ page }) => {
        await gotoDatabases(page)
        await page.getByRole('link', { name: 'storefront_production' }).click()
        await page.getByRole('button', { name: /Back up now/i }).click()

        await settled(page, 'Backup started')
    })
})
