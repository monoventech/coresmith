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
    withInertia,
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

    test('a Redis database needs only a name, and gets an instance of its own', async ({ page }) => {
        const name = `cache_${Date.now().toString(36)}`

        await gotoServerTab(page, 'anvil-01', 'Databases')
        await page.getByRole('link', { name: 'Create a database' }).click()
        await page.getByLabel('Engine').selectOption('redis')

        await expect(page.getByLabel('User', { exact: true })).toHaveCount(0)
        await expect(page.getByLabel('Privileges')).toHaveCount(0)
        await expect(page.getByTestId('redis-instance-note')).toHaveText(
            'It gets an instance of its own on a free port, with its own password. Apps connect with the host, port and password its page shows.'
        )
        await expect(page.getByRole('main')).toContainText(
            'An instance of its own, its own port, process and data, apart from every other app'
        )

        await page.getByLabel('Database name').fill(name)
        await page.getByRole('button', { name: 'Create the database' }).click()

        await expect(toast(page)).toContainText('Database queued for creation.')
        await expect(databaseRow(page, name)).toContainText('password')
        await expect(databaseRow(page, name)).toContainText(/63[89]\d|64[0-7]\d/)
    })

    test('a Redis database on its own instance shows its port and promises its own password', async ({ page }) => {
        await gotoServerTab(page, 'forge-01', 'Databases')

        await expect(databaseRow(page, 'cache_production')).toContainText('password')
        await expect(databaseRow(page, 'cache_production')).toContainText('6380')
        await databaseRow(page, 'cache_production').click()

        await expect(page.getByRole('main')).toContainText('Redis · on forge-01 · localhost only')
        await expect(page.getByTestId('database-url')).toHaveText(/^redis:\/\/[^@]+:6380$/)
        await expect(page.getByRole('main')).toContainText(
            'An instance of its own. Only apps with its password get in.'
        )
        await expect(page.getByRole('main')).not.toContainText('privileges')
        await expect(page.getByRole('button', { name: 'Show password' })).toBeVisible()
        await expect(page.getByRole('button', { name: 'Back up now' })).toHaveCount(0)
        await expect(page.getByRole('main')).toContainText(
            'Stops and removes its Redis instance on forge-01, with everything in it.'
        )
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

    test('deleting asks for the name, then a shared Redis database leaves Coresmith', async ({ page }, testInfo) => {
        const name = own(testInfo, 'doomed', '_')

        await gotoDatabase(page, 'bench-01', name)

        await page.getByRole('button', { name: 'Delete this database' }).click()
        await expect(dialog(page)).toContainText(`Delete ${name}?`)
        await expect(dialog(page)).toContainText(
            "It's removed from Coresmith. The shared Redis on bench-01 keeps running and keeps what's in it."
        )
        await expect(dialog(page).getByRole('button', { name: 'Delete it' })).toBeDisabled()
        await dialog(page).getByLabel(`Type ${name} to confirm`).fill(`${name}x`)
        await expect(dialog(page).getByRole('button', { name: 'Delete it' })).toBeDisabled()
        await dialog(page).getByLabel(`Type ${name} to confirm`).fill(name)
        await dialog(page).getByRole('button', { name: 'Delete it' }).click()

        await expect(toast(page)).toContainText(`Removed ${name}. The shared Redis on bench-01 keeps what's in it.`)
        await expect(databaseRow(page, name)).toHaveCount(0)
    })

    test('a database Coresmith cannot drop from its server stays, and the page says why', async ({ page }) => {
        await gotoDatabase(page, 'vault-01', 'vault_production')

        await page.getByRole('button', { name: 'Delete this database' }).click()
        await expect(dialog(page)).toContainText(
            'Coresmith drops it and its user from vault-01. Everything in it is gone and apps using it stop working. Take a backup first if anything still needs the data.'
        )
        await dialog(page).getByLabel('Type vault_production to confirm').fill('vault_production')
        await dialog(page).getByRole('button', { name: 'Delete it' }).click()

        await expect(toast(page)).toContainText(
            "Coresmith couldn't delete vault_production from vault-01, so it's still there. Check the server is online and nothing is using the database, then try again."
        )
        await expect(dialog(page)).toHaveCount(0)
        await expect(page.getByRole('heading', { level: 1, name: 'vault_production' })).toBeVisible()
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
    test('a password is shown once, joins the connection URL, and is never offered again', async ({
        page
    }, testInfo) => {
        const name = own(testInfo, 'reveal', '_')

        await gotoDatabase(page, 'bench-01', name)

        await expect(page.getByTestId('database-password')).toHaveCount(0)
        await expect(page.getByTestId('database-password-note')).toHaveText(
            'It can be shown once, then only rotating gives you a new one. Both are recorded in the activity log.'
        )

        const password = await showPassword(page)

        await expect(page.getByTestId('database-url')).toContainText(`mysql://${name}:${password}@`)
        await expect(page.getByTestId('database-password-note')).toHaveText(
            "Copy it now. It isn't shown again; rotate it if you lose it."
        )
        await expect(page.getByRole('button', { name: 'Copy password' })).toBeVisible()
        await expect(page.getByRole('button', { name: 'Copy URL' })).toBeVisible()

        await gotoDatabase(page, 'bench-01', name)

        await expect(page.getByTestId('database-password')).toHaveCount(0)
        await expect(page.getByTestId('database-url')).not.toContainText(password)
        await expect(page.getByRole('button', { name: 'Show password' })).toHaveCount(0)
        await expect(page.getByTestId('database-password-note')).toHaveText(
            'It was shown once and is encrypted at rest. Rotate it if you lose it.'
        )
        await expect(page.getByRole('button', { name: 'Rotate password' })).toBeVisible()
    })

    test('rotating asks first, and a server Coresmith cannot reach changes nothing', async ({ page }) => {
        await gotoDatabase(page, 'vault-01', 'vault_production')

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
        await expect(page.getByTestId('database-password')).toHaveCount(0)
        await expect(page.getByTestId('database-password-note')).toHaveText(
            'It can be shown once, then only rotating gives you a new one. Both are recorded in the activity log.'
        )
    })

    test('a Redis database on the shared instance says it has no password and offers none', async ({ page }) => {
        await databaseShowing(page, { type: 'redis', port: 6379, has_password: false, shared_redis: true })
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
                await withInertia(page, /\/databases\/[A-Za-z0-9]+$/, (body) => {
                    body.flash = { ...body.flash, databasePassword: 'Kq3vX9pLm2Rt7Yw4Zb8Nc1Hd' }
                })
                await gotoDatabase(page, 'forge-01', 'atlas_production')
                await expect(page.getByTestId('database-password')).toHaveText('password Kq3vX9pLm2Rt7Yw4Zb8Nc1Hd')
                expect(await blockingViolations(page), `shown password in ${theme}`).toEqual([])

                await page.getByRole('button', { name: 'Rotate password' }).click()
                await expect(dialog(page)).toBeVisible()
                expect(await blockingViolations(page), `rotate dialog in ${theme}`).toEqual([])
            })

            test(`creating a Redis database has no serious or critical violations in ${theme}`, async ({ page }) => {
                await useTheme(page, theme)
                await gotoServerTab(page, 'anvil-01', 'Databases')
                await page.getByRole('link', { name: 'Create a database' }).click()
                await page.getByLabel('Engine').selectOption('redis')
                await expect(page.getByTestId('redis-instance-note')).toBeVisible()

                expect(await blockingViolations(page), `redis form in ${theme}`).toEqual([])
            })

            test(`the delete dialog has no serious or critical violations in ${theme}`, async ({ page }) => {
                await useTheme(page, theme)
                await gotoDatabase(page, 'forge-01', 'atlas_production')
                await page.getByRole('button', { name: 'Delete this database' }).click()
                await dialog(page).getByLabel('Type atlas_production to confirm').fill('atlas_production')

                expect(await blockingViolations(page), `delete dialog in ${theme}`).toEqual([])
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
