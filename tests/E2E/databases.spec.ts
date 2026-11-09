import type { Page } from '@playwright/test'
import {
    asMember,
    blockingViolations,
    databaseRow,
    dialog,
    expect,
    gotoDatabase,
    gotoServerTab,
    own,
    pageData,
    test,
    toast,
    useTheme,
    withProps
} from './support/fixtures'

async function showPassword(page: Page): Promise<string> {
    await page.getByRole('button', { name: 'Show password' }).click()
    await expect(page.getByTestId('database-password')).toHaveText(/^password \S{20,}$/)

    return ((await page.getByTestId('database-password').textContent()) ?? '').replace(/^password /, '')
}

async function databaseShowing(page: Page, database: Record<string, unknown>) {
    await withProps(page, /\/databases\/[A-Za-z0-9]+$/, (props) => {
        props.database = { ...(props.database as Record<string, unknown>), ...database }
    })
}

test.describe('databases', () => {
    test('a server lists its databases with engine and user', async ({ page }) => {
        await gotoServerTab(page, 'forge-01', 'Databases')

        await expect(databaseRow(page, 'atlas_production')).toBeVisible()
        await expect(databaseRow(page, 'ledger_production')).toBeVisible()
        await expect(page.getByTestId('databases-table')).toContainText(
            "Databases Coresmith installed listen on localhost only; it doesn't control network access for the rest."
        )
        await expect(databaseRow(page, 'legacy_reports')).toContainText('existing server')
    })

    test('a server whose databases are all its own installs keeps the plain caption', async ({ page }) => {
        await gotoServerTab(page, 'anvil-01', 'Databases')

        await expect(page.getByTestId('databases-table')).toContainText(
            'Databases listen on localhost only. Each one has its own credentials, never shared between apps.'
        )
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

        const html = await pageData(page)

        expect(html).toContain('atlas_production')
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

        await expect(page.getByTestId('external-database-note')).toContainText(
            'It never reconfigures, restarts or removes that server'
        )
        await expect(page.getByRole('main')).toContainText('existing server')
        await expect(page.getByRole('main')).not.toContainText('localhost only')
    })
})

test.describe('database passwords', () => {
    test('an owner shows the password, which joins the connection URL and goes when the page does', async ({
        page
    }) => {
        await gotoDatabase(page, 'forge-01', 'atlas_production')

        await expect(page.getByTestId('database-password')).toHaveCount(0)
        await expect(page.getByRole('main')).toContainText(
            'The password is encrypted at rest. Showing or rotating it is recorded in the activity log.'
        )

        const password = await showPassword(page)

        await expect(page.getByTestId('database-url')).toContainText(`postgresql://atlas_app:${password}@`)
        await expect(page.getByRole('main')).toContainText("Copy it now. It's hidden again when you leave this page.")
        await expect(page.getByRole('button', { name: 'Copy password' })).toBeVisible()
        await expect(page.getByRole('button', { name: 'Copy URL' })).toBeVisible()

        await gotoDatabase(page, 'forge-01', 'atlas_production')

        await expect(page.getByTestId('database-password')).toHaveCount(0)
        await expect(page.getByTestId('database-url')).not.toContainText(password)
        await expect(page.getByRole('button', { name: 'Show password' })).toBeVisible()
    })

    test('rotating asks first, and a server Coresmith cannot reach keeps the old password', async ({ page }) => {
        await gotoDatabase(page, 'vault-01', 'vault_production')
        const before = await showPassword(page)

        await page.getByRole('button', { name: 'Rotate password' }).click()
        await expect(dialog(page)).toContainText('Rotate the password of vault_production?')
        await expect(dialog(page)).toContainText(
            'Apps using the current password lose access until you give them the new one and deploy.'
        )
        await dialog(page).getByRole('button', { name: 'Rotate it' }).click()

        await expect(toast(page)).toContainText(
            "Coresmith couldn't change the password on vault-01, so the old one still works. Check the server is online, then try again."
        )
        await expect(dialog(page)).toHaveCount(0)

        expect(await showPassword(page)).toBe(before)
    })

    test('a Redis database on the shared instance says it has no password and offers none', async ({ page }) => {
        await databaseShowing(page, { type: 'redis', port: 6379, has_password: false })
        await gotoDatabase(page, 'forge-01', 'atlas_production')

        await expect(page.getByTestId('shared-redis-note')).toHaveText(
            "It has no password, so every app on forge-01 can read and change what's in it."
        )
        await expect(page.getByTestId('database-url')).toHaveText(/^redis:\/\/[^@]+:6379$/)
        await expect(page.getByRole('main')).toContainText('No user of its own. Every app on forge-01 shares it.')
        await expect(page.getByRole('button', { name: 'Show password' })).toHaveCount(0)
        await expect(page.getByRole('button', { name: 'Rotate password' })).toHaveCount(0)
    })

    test.describe('accessibility', () => {
        test.use({ reducedMotion: 'reduce' })

        for (const theme of ['light', 'dark'] as const) {
            test(`a shown password and the rotate dialog have no serious or critical violations in ${theme}`, async ({
                page
            }) => {
                await useTheme(page, theme)
                await gotoDatabase(page, 'forge-01', 'atlas_production')
                await showPassword(page)
                expect(await blockingViolations(page), `shown password in ${theme}`).toEqual([])

                await page.getByRole('button', { name: 'Rotate password' }).click()
                await expect(dialog(page)).toBeVisible()
                expect(await blockingViolations(page), `rotate dialog in ${theme}`).toEqual([])
            })
        }
    })
})

asMember('a deployer sees how to connect but cannot show or rotate the password', async ({ page }) => {
    await gotoDatabase(page, 'forge-01', 'atlas_production')

    await expect(page.getByRole('heading', { name: 'Connection' })).toBeVisible()
    await expect(page.getByRole('button', { name: 'Show password' })).toHaveCount(0)
    await expect(page.getByRole('button', { name: 'Rotate password' })).toHaveCount(0)
})
