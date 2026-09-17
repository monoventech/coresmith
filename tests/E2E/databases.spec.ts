import { databaseRow, dialog, expect, gotoDatabase, gotoServerTab, own, test, toast } from './support/fixtures'

test.describe('databases', () => {
    test('a server lists its databases with engine and user', async ({ page }) => {
        await gotoServerTab(page, 'forge-01', 'Databases')

        await expect(databaseRow(page, 'atlas_production')).toBeVisible()
        await expect(databaseRow(page, 'ledger_production')).toBeVisible()
        await expect(page.getByTestId('databases-table')).toContainText("Databases Coresmith installed listen on localhost only; it doesn't control network access for the rest.")
        await expect(databaseRow(page, 'legacy_reports')).toContainText('existing server')
    })

    test('a server whose databases are all its own installs keeps the plain caption', async ({ page }) => {
        await gotoServerTab(page, 'anvil-01', 'Databases')

        await expect(page.getByTestId('databases-table')).toContainText('Databases listen on localhost only. Each one has its own credentials, never shared between apps.')
    })

    test('a server without databases explains and offers to create one', async ({ page }) => {
        await gotoServerTab(page, 'forge-02', 'Databases')

        await expect(page.getByText('No databases on this server.')).toBeVisible()
        await expect(page.getByRole('link', { name: 'Create a database' })).toBeVisible()
    })

    test('a server that is not connected cannot create one yet', async ({ page }) => {
        await gotoServerTab(page, 'empty-server', 'Databases')

        await expect(page.getByText('No databases on this server.')).toBeVisible()
        await expect(page.getByRole('link', { name: 'Create a database' })).toHaveCount(0)
    })

    test('a database is created with a user of its own', async ({ page }) => {
        const name = `shop_${Date.now().toString(36)}`

        await gotoServerTab(page, 'anvil-01', 'Databases')
        await page.getByRole('link', { name: 'Create a database' }).click()

        await expect(page.getByRole('heading', { level: 1, name: 'Create a database' })).toBeVisible()
        await page.getByLabel('Database name').fill(name)
        await page.getByLabel('User', { exact: true }).fill(`${name}_app`)
        await page.getByRole('button', { name: 'Create the database' }).click()

        await expect(toast(page)).toContainText('Database queued for creation.')
        await expect(databaseRow(page, name)).toBeVisible()
    })

    test('a name carrying sql metacharacters is refused next to the field', async ({ page }) => {
        await gotoServerTab(page, 'anvil-01', 'Databases')
        await page.getByRole('link', { name: 'Create a database' }).click()

        await page.getByLabel('Database name').fill('shop`; DROP DATABASE mysql; --')
        await page.getByLabel('User', { exact: true }).fill('shop_user')
        await page.getByRole('button', { name: 'Create the database' }).click()

        await expect(page.getByLabel('Database name')).toHaveAttribute('aria-invalid', 'true')
    })

    test('a database shows how to connect but never a password', async ({ page }) => {
        await gotoDatabase(page, 'forge-01', 'atlas_production')

        await expect(page.getByRole('heading', { name: 'Connection' })).toBeVisible()
        await expect(page.getByRole('main')).toContainText('PostgreSQL · on forge-01 · localhost only')
        await expect(page.getByRole('main')).toContainText('postgresql://atlas_app@')

        const html = await page.content()

        expect(html).not.toContain('db_password')
    })

    test('a backup is started from the database', async ({ page }) => {
        await gotoDatabase(page, 'forge-01', 'atlas_production')

        await page.getByRole('button', { name: 'Back up now' }).click()

        await expect(toast(page)).toContainText('Backup started.')
    })

    test('deleting asks first, then removes it from the panel', async ({ page }, testInfo) => {
        const name = own(testInfo, 'doomed', '_')

        await gotoDatabase(page, 'bench-01', name)

        await page.getByRole('button', { name: 'Delete this database' }).click()
        await expect(dialog(page)).toContainText(`Delete ${name}?`)
        await dialog(page).getByRole('button', { name: 'Delete it' }).click()

        await expect(toast(page)).toContainText('Database removed.')
        await expect(databaseRow(page, name)).toHaveCount(0)
    })
})

test.describe('a database on a server that was already there', () => {
    test('it says Coresmith leaves that server alone and does not promise localhost only', async ({ page }) => {
        await gotoDatabase(page, 'forge-01', 'legacy_reports')

        await expect(page.getByTestId('external-database-note')).toContainText('It never reconfigures, restarts or removes that server')
        await expect(page.getByRole('main')).toContainText('existing server')
        await expect(page.getByRole('main')).not.toContainText('localhost only')
    })
})
