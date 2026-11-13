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

        await expect(toast(page)).toContainText('Creating the database.')
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

        await expect(toast(page)).toContainText('Creating the database.')
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

test.describe('backups and restore', () => {
    const rows = [
        { stamp: '20261111020000', when: 'Nov 11, 02:00', size: '2 KB', on_server: true, in_s3: true },
        { stamp: '20261110023000', when: 'Nov 10, 02:30', size: '1.5 MB', on_server: true, in_s3: false },
        { stamp: '20261109023000', when: 'Nov 9, 02:30', size: '1.4 MB', on_server: false, in_s3: true }
    ]

    async function dialogSettled(page: Page) {
        await dialog(page).evaluate((element) =>
            Promise.all(element.getAnimations({ subtree: true }).map((animation) => animation.finished))
        )
    }

    async function backupsShowing(page: Page, props: Record<string, unknown> = {}) {
        await withProps(page, /\/databases\/[A-Za-z0-9]+$/, (current) => {
            current.backups = { rows, server_error: null, s3_error: null }
            current.offsite = { sent: 'today 02:30', error: null }
            current.restore = null
            current.apps = [
                { id: 'app-storefront', name: 'atlas', uses: true, running: true },
                { id: 'app-relay', name: 'relay', uses: false, running: true },
                { id: 'app-ledger', name: 'ledger', uses: true, running: false }
            ]
            current.can = { ...(current.can as Record<string, unknown>), restore: true, backup: true }
            Object.assign(current, props)
        })
    }

    test('the Backups card lists dumps on the server and copies in S3', async ({ page }) => {
        await backupsShowing(page)
        await gotoDatabase(page, 'forge-01', 'atlas_production')

        const card = page.getByTestId('backups-card')
        await expect(card).toContainText(
            'Dumps on forge-01, and copies in S3. Restoring replaces everything in atlas_production.'
        )
        await expect(card.getByTestId('backup-row')).toHaveText([
            'Nov 11, 02:00 · 2 KB · on forge-01 and in S3 Restore',
            'Nov 10, 02:30 · 1.5 MB · on forge-01 Restore',
            'Nov 9, 02:30 · 1.4 MB · in S3 Restore'
        ])
    })

    test('no backups explains and offers Back up now', async ({ page }) => {
        await backupsShowing(page, { backups: { rows: [], server_error: null, s3_error: null } })
        await gotoDatabase(page, 'forge-01', 'atlas_production')

        const card = page.getByTestId('backups-card')
        await expect(card.getByTestId('backups-empty')).toHaveText('No backups yet. Back up now to make the first one.')
        await expect(card.getByRole('button', { name: 'Back up now' })).toBeVisible()
    })

    test('an unreachable server is named and S3 copies still show', async ({ page }) => {
        await backupsShowing(page, {
            backups: {
                rows: [rows[2]],
                server_error: "Coresmith couldn't reach forge-01 to list its backups.",
                s3_error: null
            }
        })
        await gotoDatabase(page, 'forge-01', 'atlas_production')

        const card = page.getByTestId('backups-card')
        await expect(card.getByTestId('backups-server-error')).toHaveText(
            "Coresmith couldn't reach forge-01 to list its backups."
        )
        await expect(card.getByTestId('backup-row')).toHaveText(['Nov 9, 02:30 · 1.4 MB · in S3 Restore'])
    })

    test('while the list is on its way, the card says so', async ({ page }) => {
        await backupsShowing(page, { backups: null })
        await gotoDatabase(page, 'forge-01', 'atlas_production')

        await expect(page.getByTestId('backups-loading')).toHaveText('Listing backups…')
    })

    test('restoring takes two steps and names what is lost', async ({ page }) => {
        await backupsShowing(page)
        await gotoDatabase(page, 'forge-01', 'atlas_production')

        await page.getByRole('button', { name: 'Restore the backup from Nov 10, 02:30' }).click()
        await expect(
            dialog(page).getByRole('heading', { name: 'Restore atlas_production from Nov 10, 02:30?' })
        ).toBeVisible()
        await expect(dialog(page)).toContainText(
            'Everything in atlas_production is replaced with the backup from Nov 10, 02:30. Changes since then are lost.'
        )
        await expect(dialog(page)).toContainText(
            'Coresmith backs up atlas_production as it is now first, so you can go back.'
        )
        await expect(dialog(page).getByLabel('atlas')).toBeChecked()
        await expect(dialog(page).getByLabel('relay')).not.toBeChecked()
        await expect(dialog(page).getByLabel('ledger')).toHaveCount(0)
        await expect(dialog(page)).toContainText("Visitors see the maintenance page and workers stop until it's done.")

        await dialog(page).getByLabel('relay').click()
        await dialog(page).getByRole('button', { name: 'Continue' }).click()

        await expect(dialog(page).getByRole('heading', { name: 'Type atlas_production to restore it.' })).toBeVisible()
        const restore = dialog(page).getByRole('button', { name: 'Restore atlas_production' })
        await expect(restore).toBeDisabled()
        await dialog(page).getByLabel('Type atlas_production to restore it').fill('atlas_productio')
        await expect(restore).toBeDisabled()
        await dialog(page).getByLabel('Type atlas_production to restore it').fill('atlas_production')

        await page.route('**/databases/*/restore', (route) =>
            route.fulfill({ status: 409, headers: { 'x-inertia-location': page.url() } })
        )
        const sent = page.waitForRequest((request) => request.url().endsWith('/restore') && request.method() === 'POST')
        await restore.click()

        expect((await sent).postDataJSON()).toEqual({
            stamp: '20261110023000',
            name: 'atlas_production',
            stop: ['app-storefront', 'app-relay']
        })
    })

    test('cancelling either step restores nothing', async ({ page }) => {
        await backupsShowing(page)
        await gotoDatabase(page, 'forge-01', 'atlas_production')

        await page.getByRole('button', { name: 'Restore the backup from Nov 11, 02:00' }).click()
        await dialog(page).getByRole('button', { name: 'Cancel' }).click()
        await expect(dialog(page)).toHaveCount(0)

        await page.getByRole('button', { name: 'Restore the backup from Nov 11, 02:00' }).click()
        await dialog(page).getByRole('button', { name: 'Continue' }).click()
        await dialog(page).getByRole('button', { name: 'Cancel' }).click()
        await expect(dialog(page)).toHaveCount(0)
    })

    test('a running restore shows its progress and a failed one says why', async ({ page }) => {
        await backupsShowing(page, {
            restore: { state: 'running', when: 'Nov 10, 02:30', step: 'restoring', error: null }
        })
        await gotoDatabase(page, 'forge-01', 'atlas_production')

        const status = page.getByTestId('restore-status')
        await expect(status).toContainText('Restoring atlas_production from Nov 10, 02:30…')
        await expect(status).toContainText("Replacing what's in atlas_production.")
        await expect(page.getByRole('button', { name: 'Restore the backup from Nov 11, 02:00' })).toBeDisabled()
        await expect(page.getByRole('button', { name: 'Back up now' }).first()).toBeDisabled()
        await page.unrouteAll({ behavior: 'ignoreErrors' })

        await backupsShowing(page, {
            restore: {
                state: 'failed',
                when: 'Nov 10, 02:30',
                step: null,
                error: "Coresmith couldn't restore the backup from Nov 10, 02:30, so it put back the copy it took first. ERROR 1064"
            }
        })
        await page.reload()

        await expect(page.getByTestId('restore-status')).toHaveText(
            "Coresmith couldn't restore the backup from Nov 10, 02:30, so it put back the copy it took first. ERROR 1064"
        )
    })

    for (const theme of ['light', 'dark'] as const) {
        test(`the Backups card and both restore steps have no serious or critical violations in ${theme}`, async ({
            page
        }) => {
            await useTheme(page, theme)
            await backupsShowing(page, {
                restore: { state: 'done', when: 'Nov 9, 02:30', step: null, error: null }
            })
            await gotoDatabase(page, 'forge-01', 'atlas_production')
            await expect(page.getByTestId('backup-row').first()).toBeVisible()
            expect(await blockingViolations(page), `backups card in ${theme}`).toEqual([])

            await page.getByRole('button', { name: 'Restore the backup from Nov 10, 02:30' }).click()
            await expect(dialog(page)).toBeVisible()
            await dialogSettled(page)
            expect(await blockingViolations(page), `restore step 1 in ${theme}`).toEqual([])

            await dialog(page).getByRole('button', { name: 'Continue' }).click()
            await expect(
                dialog(page).getByRole('heading', { name: 'Type atlas_production to restore it.' })
            ).toBeVisible()
            expect(await blockingViolations(page), `restore step 2 in ${theme}`).toEqual([])
        })
    }
})

test.describe('the shared Redis', () => {
    test('a database on it is offered a Redis of its own, and a server Coresmith cannot reach keeps it shared', async ({
        page
    }) => {
        await gotoDatabase(page, 'vault-01', 'vault_cache')

        await page.getByRole('button', { name: 'Give it its own Redis' }).click()
        await expect(dialog(page)).toContainText('Give vault_cache a Redis of its own?')
        await expect(dialog(page)).toContainText(
            "What's in the shared Redis stays there: queued jobs, sessions and cached values don't move."
        )
        await dialog(page).getByRole('button', { name: 'Give it its own Redis' }).click()

        await expect(toast(page)).toContainText(
            "Coresmith couldn't start a Redis of its own for vault_cache on vault-01, so it's still on the shared Redis. Check the server is online, then try again."
        )
        await expect(page.getByTestId('shared-redis-note')).toBeVisible()
    })

    test('the Databases tab counts the databases still on it', async ({ page }) => {
        await gotoServerTab(page, 'vault-01', 'Databases')

        await expect(page.getByTestId('shared-redis')).toHaveText(
            "1 Redis database still uses the shared Redis on port 6379, which has no password, so every app on vault-01 can read and change what's in it. Give each one a Redis of its own from its page."
        )
        await expect(page.getByRole('button', { name: 'Turn off the shared Redis' })).toHaveCount(0)
    })

    test('an unused shared Redis is offered to be turned off, and a server Coresmith cannot reach keeps it on', async ({
        page
    }) => {
        await gotoServerTab(page, 'vault-02', 'Databases')

        await expect(page.getByTestId('shared-redis')).toContainText(
            'No database uses the shared Redis on port 6379 any more, but it still answers without a password.'
        )
        await page.getByRole('button', { name: 'Turn off the shared Redis' }).click()
        await expect(dialog(page)).toContainText('Turn off the shared Redis on vault-02?')
        await dialog(page).getByRole('button', { name: 'Turn it off' }).click()

        await expect(toast(page)).toContainText(
            "Coresmith couldn't turn off the shared Redis on vault-02. Check the server is online, then try again."
        )
        await expect(page.getByRole('button', { name: 'Turn off the shared Redis' })).toBeVisible()
    })

    test('what Coresmith runs here names the shared Redis as the exception to credentials per app', async ({
        page
    }) => {
        await gotoServerTab(page, 'vault-02', 'Hardening')
        await page.getByTestId('under-the-hood-link').click()

        await expect(page.getByTestId('machinery-isolation')).toContainText(
            'Database credentials per app, except the shared Redis on port 6379'
        )
    })

    test.describe('accessibility', () => {
        test.use({ reducedMotion: 'reduce' })

        for (const theme of ['light', 'dark'] as const) {
            test(`the shared Redis card and its dialogs have no serious or critical violations in ${theme}`, async ({
                page
            }) => {
                await useTheme(page, theme)
                await gotoServerTab(page, 'vault-02', 'Databases')
                await page.getByRole('button', { name: 'Turn off the shared Redis' }).click()
                await expect(dialog(page)).toBeVisible()
                expect(await blockingViolations(page), `turn off dialog in ${theme}`).toEqual([])

                await gotoDatabase(page, 'vault-01', 'vault_cache')
                await page.getByRole('button', { name: 'Give it its own Redis' }).click()
                await expect(dialog(page)).toBeVisible()
                expect(await blockingViolations(page), `move dialog in ${theme}`).toEqual([])
            })
        }
    })
})

asMember('a deployer sees the shared Redis but cannot move a database off it or turn it off', async ({ page }) => {
    await gotoDatabase(page, 'vault-01', 'vault_cache')
    await expect(page.getByTestId('shared-redis-note')).toBeVisible()
    await expect(page.getByRole('button', { name: 'Give it its own Redis' })).toHaveCount(0)

    await gotoServerTab(page, 'vault-02', 'Databases')
    await expect(page.getByTestId('shared-redis')).toBeVisible()
    await expect(page.getByRole('button', { name: 'Turn off the shared Redis' })).toHaveCount(0)
})

asMember('a deployer sees how to connect but cannot show or rotate the password', async ({ page }) => {
    await gotoDatabase(page, 'forge-01', 'atlas_production')

    await expect(page.getByRole('heading', { name: 'Connection' })).toBeVisible()
    await expect(page.getByRole('button', { name: 'Show password' })).toHaveCount(0)
    await expect(page.getByRole('button', { name: 'Rotate password' })).toHaveCount(0)
})
