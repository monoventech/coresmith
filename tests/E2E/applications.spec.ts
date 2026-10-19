import type { Page } from '@playwright/test'
import {
    applicationRow,
    blockingViolations,
    dialog,
    expect,
    gotoApplication,
    gotoServer,
    isMobile,
    own,
    test,
    toast,
    useTheme
} from './support/fixtures'

function toggle(page: Page, name: string) {
    return page.getByTestId('smart-toggles').getByRole('switch', { name })
}

test.describe('an application', () => {
    test('the header names it and says how it is served', async ({ page }) => {
        await gotoApplication(page, 'forge-01', 'atlas')

        await expect(page.getByTestId('back-link')).toHaveText('← forge-01')
        await expect(page.getByRole('main')).toContainText('Live')
        await expect(page.getByRole('main')).toContainText('atlas.monoven.io')
        await expect(page.getByRole('main')).toContainText('Laravel 13 · PHP 8.5')
        await expect(page.getByRole('button', { name: 'Deploy', exact: true })).toBeVisible()
    })

    test('a laravel app gets the toggles laravel apps need', async ({ page }) => {
        await gotoApplication(page, 'forge-01', 'atlas')

        await expect(toggle(page, 'Queue worker')).toBeChecked()
        await expect(toggle(page, 'Scheduler')).toBeChecked()
        await expect(toggle(page, 'Maintenance page')).not.toBeChecked()
    })

    test('a rails app gets sidekiq instead', async ({ page }) => {
        await gotoApplication(page, 'forge-01', 'ledger')

        await expect(toggle(page, 'Sidekiq')).toBeVisible()
        await expect(toggle(page, 'Queue worker')).toHaveCount(0)
    })

    test('the current release is shown', async ({ page }) => {
        await gotoApplication(page, 'forge-01', 'atlas')

        await expect(page.getByRole('heading', { name: 'Current release' })).toBeVisible()
        await expect(page.getByRole('main')).toContainText(/current → releases\/\d{8}-\d{4}/)
    })

    test('the back link returns to the server', async ({ page }) => {
        await gotoApplication(page, 'forge-01', 'atlas')

        await page.getByTestId('back-link').click()

        await expect(page.getByRole('heading', { level: 1, name: 'forge-01' })).toBeVisible()
    })

    test('the environment preview masks secrets', async ({ page }) => {
        await gotoApplication(page, 'forge-01', 'atlas')

        const preview = page.getByTestId('env-preview')

        await expect(preview).toContainText('APP_ENV=production')
        await expect(preview).toContainText('DB_PASSWORD=••••••••')
        await expect(preview).toContainText('redacted in logs')
    })

    test('a public https repository needs no deploy key', async ({ page }, testInfo) => {
        await gotoApplication(page, 'bench-01', own(testInfo, 'scratch'))

        await expect(page.getByRole('heading', { name: 'Deploy key' })).toHaveCount(0)
    })

    test('the live log reads the access log in the background', async ({ page }) => {
        await gotoApplication(page, 'forge-01', 'atlas')

        await expect(page.getByTestId('live-log')).toHaveAttribute('role', 'log')
        await expect(page.getByTestId('live-log')).toContainText(/reading the access log|No requests|could not be read/)
    })

    test('an app whose last deploy failed says nothing changed and links the log', async ({ page }) => {
        await gotoApplication(page, 'forge-01', 'storefront')

        const banner = page.getByTestId('failed-banner')

        await expect(banner).toContainText('The last deploy failed, so nothing changed.')
        await banner.getByRole('link', { name: 'Read the log' }).click()

        await expect(page.getByRole('heading', { level: 1, name: 'Deploying storefront' })).toBeVisible()
        await expect(page.getByTestId('back-link')).toHaveText('← storefront')
    })

    test('an app with a deploy running offers to watch it instead of deploying again', async ({ page }) => {
        await gotoApplication(page, 'forge-02', 'atlas-staging')

        await expect(page.getByRole('button', { name: 'Deploy', exact: true })).toHaveCount(0)
        await page.getByRole('link', { name: 'Watch deploy' }).click()

        await expect(page.getByRole('heading', { level: 1, name: 'Deploying atlas-staging' })).toBeVisible()
        await expect(page.getByTestId('back-link')).toHaveText('← atlas-staging')
    })

    test('deploying opens the deploy screen and returns to the app', async ({ page }, testInfo) => {
        const name = own(testInfo, 'scratch')

        await gotoApplication(page, 'bench-01', name)
        await page.getByRole('button', { name: 'Deploy', exact: true }).click()

        await expect(page.getByRole('heading', { level: 1, name: `Deploying ${name}` })).toBeVisible()
        await expect(toast(page)).toContainText(`Deploying ${name}.`)

        await page.getByTestId('back-link').click()
        await expect(page.getByRole('heading', { level: 1, name, exact: true })).toBeVisible()
    })

    test('on a phone the app page keeps deploy, the toggles and the current release', async ({ page }, testInfo) => {
        test.skip(!isMobile(testInfo), 'The compact app page only renders below 640px')

        await gotoApplication(page, 'forge-01', 'atlas')

        await expect(page.getByRole('button', { name: 'Deploy', exact: true })).toBeVisible()
        await expect(page.getByTestId('smart-toggles')).toBeVisible()
        await expect(page.getByRole('heading', { name: 'Current release' })).toBeVisible()
        await expect(page.getByRole('heading', { name: 'Environment' })).toHaveCount(0)
        await expect(page.getByRole('heading', { name: 'Domains' })).toHaveCount(0)
        await expect(page.getByRole('button', { name: 'Rename' })).toHaveCount(0)
    })
})

test.describe('changing an application', () => {
    test('maintenance mode is turned on and off and remembered', async ({ page }, testInfo) => {
        await gotoApplication(page, 'bench-01', own(testInfo, 'scratch'))

        await toggle(page, 'Maintenance page').click()

        await expect(toast(page)).toContainText('Saved.')
        await expect(page.getByTestId('offline-banner')).toContainText('Maintenance mode is on.')
        await expect(toggle(page, 'Maintenance page')).toBeChecked()

        await page.reload()
        await expect(toggle(page, 'Maintenance page')).toBeChecked()

        await toggle(page, 'Maintenance page').click()
        await expect(toggle(page, 'Maintenance page')).not.toBeChecked()
        await expect(page.getByTestId('offline-banner')).toHaveCount(0)
    })

    test('a queue worker is started from its toggle', async ({ page }, testInfo) => {
        const name = own(testInfo, 'scratch')

        await gotoApplication(page, 'bench-01', name)

        await expect(page.getByTestId('toggle-queue_worker')).toContainText(`systemd unit coresmith-app-${name}-queue`)
        await expect(toggle(page, 'Queue worker')).not.toBeChecked()

        await toggle(page, 'Queue worker').click()
        await expect(toggle(page, 'Queue worker')).toBeChecked()

        await page.reload()
        await expect(toggle(page, 'Queue worker')).toBeChecked()

        await toggle(page, 'Queue worker').click()
        await expect(toggle(page, 'Queue worker')).not.toBeChecked()
    })

    test('a stopped app offers start and cannot also go into maintenance', async ({ page }) => {
        await gotoApplication(page, 'bench-01', 'retired-blog')

        await expect(page.getByRole('main')).toContainText('Stopped')
        await expect(page.getByTestId('offline-banner').getByRole('button', { name: 'Start it' })).toBeVisible()
        await expect(page.getByTestId('offline-banner')).toContainText(
            'Visitors see a maintenance page until you start it again.'
        )
        await expect(toggle(page, 'Maintenance page')).toBeDisabled()
        await expect(page.getByTestId('toggle-maintenance_mode')).toContainText(
            'The application is stopped, so visitors already see one.'
        )
    })
})

test.describe('what the full app page adds on a wider screen', () => {
    test.skip(
        ({ isMobile }) => isMobile,
        'The phone app page keeps only Deploy, the toggles and the current release, as the mobile prototype draws it'
    )

    test('a repository cloned over ssh shows its read-only deploy key', async ({ page }) => {
        await gotoApplication(page, 'forge-01', 'atlas')

        const key = page.getByRole('heading', { name: 'Deploy key' }).locator('..')

        await expect(key).toContainText('Per-app Ed25519, read-only.')
        await expect(key).toContainText('monoventech/atlas')
        await expect(key.getByRole('button', { name: 'Copy public key' })).toBeVisible()

        const html = await page.content()

        expect(html).not.toContain('BEGIN OPENSSH PRIVATE KEY')
        expect(html).not.toContain('deploy_key_private')
    })

    test('domains are listed with their certificates', async ({ page }) => {
        await gotoApplication(page, 'forge-01', 'atlas')

        const domains = page.getByRole('heading', { name: 'Domains' }).locator('../..')

        await expect(domains).toContainText('www.atlas.monoven.io')
        await expect(domains).toContainText('primary')
        await expect(domains).toContainText('alias')
        await expect(page.getByRole('main')).toContainText('atlas.monoven.io via Caddy')
    })

    test('the releases link opens this app’s releases', async ({ page }) => {
        await gotoApplication(page, 'forge-01', 'atlas')

        await page.getByRole('link', { name: 'All releases →' }).click()

        await expect(page.getByRole('heading', { level: 1, name: 'Releases · atlas' })).toBeVisible()
    })

    test('stopping an app puts up the maintenance page until it starts again', async ({ page }, testInfo) => {
        await gotoApplication(page, 'bench-01', own(testInfo, 'scratch'))

        await expect(page.getByRole('button', { name: 'Deploy', exact: true })).toBeVisible()
        await page.getByRole('button', { name: 'Stop the app' }).click()

        await expect(toast(page)).toContainText('Stopping.')
        await expect(page.getByTestId('offline-banner')).toContainText('This application is stopped.')

        await page.getByTestId('offline-banner').getByRole('button', { name: 'Start it' }).click()

        await expect(toast(page)).toContainText('Starting.')
        await expect(page.getByTestId('offline-banner')).toHaveCount(0)
        await expect(page.getByRole('button', { name: 'Stop the app' })).toBeVisible()
    })

    test('environment variables are edited in a dialog and previewed after saving', async ({ page }, testInfo) => {
        const run = `RUN_${testInfo.project.name.replace('-', '_').toUpperCase()}`

        await gotoApplication(page, 'bench-01', own(testInfo, 'scratch'))

        await page.getByRole('button', { name: 'Edit variables' }).click()

        const editor = dialog(page).getByRole('textbox', { name: 'Environment variables' })

        await expect(editor).toHaveValue(/APP_ENV=staging/)
        await editor.fill(`APP_ENV=staging\n${run}=yes`)
        await dialog(page).getByRole('button', { name: 'Save' }).click()

        await expect(toast(page)).toContainText('Saved.')
        await expect(dialog(page)).toHaveCount(0)
        await expect(page.getByTestId('env-preview')).toContainText(`${run}=yes`)
    })

    test('environment text the server could not read is refused with the line to fix', async ({ page }, testInfo) => {
        await gotoApplication(page, 'bench-01', own(testInfo, 'scratch'))

        await page.getByRole('button', { name: 'Edit variables' }).click()

        const editor = dialog(page).getByRole('textbox', { name: 'Environment variables' })

        await editor.fill('APP_ENV=staging\nAPP_NAME="Shop')
        await dialog(page).getByRole('button', { name: 'Save' }).click()

        await expect(dialog(page)).toContainText('Line 2 opens a quote that never closes. Close it or remove it.')
        await expect(editor).toHaveAttribute('aria-invalid', 'true')

        await editor.fill('APP_ENV=staging\nAPP_NAME="Shop"')

        await expect(editor).not.toHaveAttribute('aria-invalid', 'true')
        await expect(dialog(page)).not.toContainText('Line 2 opens a quote')
    })

    test('a domain that is not a domain is refused before it is sent', async ({ page }, testInfo) => {
        await gotoApplication(page, 'bench-01', own(testInfo, 'scratch'))

        await page.getByRole('button', { name: 'Add domain' }).click()
        await dialog(page).getByRole('textbox', { name: 'Domain' }).fill('not a domain')
        await dialog(page).getByRole('button', { name: 'Add domain' }).click()

        await expect(dialog(page)).toContainText("That doesn't look like a domain. Try app.example.com.")
        await expect(dialog(page).getByRole('textbox', { name: 'Domain' })).toHaveAttribute('aria-invalid', 'true')
    })

    test('a domain is added as an alias', async ({ page }, testInfo) => {
        const domain = `${own(testInfo, 'alias')}-${Date.now().toString(36)}.bench.monoven.io`

        await gotoApplication(page, 'bench-01', own(testInfo, 'scratch'))

        await page.getByRole('button', { name: 'Add domain' }).click()
        await dialog(page).getByRole('textbox', { name: 'Domain' }).fill(domain)
        await dialog(page).getByRole('button', { name: 'Add domain' }).click()

        await expect(toast(page)).toContainText('Saved.')
        await expect(page.getByRole('main')).toContainText(domain)
    })

    test('removing an app asks first and detaches it from the server', async ({ page }, testInfo) => {
        const name = own(testInfo, 'doomed')

        await gotoApplication(page, 'bench-01', name)

        await page.getByRole('button', { name: 'Remove this app' }).click()
        await expect(dialog(page)).toContainText(`Remove ${name}?`)
        await dialog(page).getByRole('button', { name: 'Remove the app' }).click()

        await expect(page.getByRole('heading', { level: 1, name: 'bench-01' })).toBeVisible()
        await expect(toast(page)).toContainText('Removed.')
        await expect(applicationRow(page, name)).toHaveCount(0)
    })
})

test.describe('adding an application', () => {
    test('the server offers to add an app', async ({ page }) => {
        await gotoServer(page, 'bench-01')

        await page.getByRole('link', { name: 'Add an app' }).click()

        await expect(page.getByRole('heading', { level: 1, name: 'Add an app' })).toBeVisible()
        await expect(page.getByRole('main')).toContainText('What a deploy does')
    })

    test('an app is created and opens on its own page', async ({ page }) => {
        const name = `e2e${Date.now().toString(36)}`

        await gotoServer(page, 'bench-01')
        await page.getByRole('link', { name: 'Add an app' }).click()

        await page.getByLabel('Name', { exact: true }).fill(name)
        await page.getByLabel('Language').selectOption('js')
        await expect(page.getByLabel('Framework')).toHaveValue('sveltekit')
        await page.getByLabel('Domain').fill(`${name}.bench.monoven.io`)
        await page.getByRole('button', { name: 'Add the app' }).click()

        await expect(page.getByRole('heading', { level: 1, name, exact: true })).toBeVisible()
        await expect(toast(page)).toContainText('Application created.')
    })

    test('a malformed domain is refused next to the field', async ({ page }) => {
        await gotoServer(page, 'bench-01')
        await page.getByRole('link', { name: 'Add an app' }).click()

        await page.getByLabel('Name', { exact: true }).fill('bad-domain')
        await page.getByLabel('Domain').fill('evil.com, other.com')
        await page.getByRole('button', { name: 'Add the app' }).click()

        await expect(page.getByLabel('Domain')).toHaveAttribute('aria-invalid', 'true')
    })

    test('the runtime version defaults to the repository and lists supported versions with their status', async ({
        page
    }) => {
        await gotoServer(page, 'bench-01')
        await page.getByRole('link', { name: 'Add an app' }).click()

        const picker = page.getByRole('radiogroup', { name: 'PHP version' })

        await expect(picker.getByRole('radio', { name: /^From the repository/ })).toBeChecked()
        await expect(picker.getByRole('radio', { name: /^8\.5\.10 recommended/ })).toBeVisible()
        await expect(picker.getByRole('radio', { name: /^8\.1\.34 End of life/ })).toBeVisible()
        await expect(page.getByRole('main')).toContainText('Used only when the repository does not name a version.')
    })

    test('choosing an end-of-life version asks first, and backing out restores the previous choice', async ({
        page
    }) => {
        await gotoServer(page, 'bench-01')
        await page.getByRole('link', { name: 'Add an app' }).click()

        const picker = page.getByRole('radiogroup', { name: 'PHP version' })

        await picker.getByRole('radio', { name: /^8\.4\.25/ }).click()
        await picker.getByRole('radio', { name: /^8\.1\.34/ }).click()

        await expect(dialog(page)).toContainText('PHP 8.1 no longer gets security fixes')
        await dialog(page).getByRole('button', { name: 'Pick another version' }).click()

        await expect(picker.getByRole('radio', { name: /^8\.4\.25/ })).toBeChecked()
    })

    test('an end-of-life version can still be chosen once confirmed, and the app is added', async ({ page }) => {
        const name = `eol${Date.now().toString(36)}`

        await gotoServer(page, 'bench-01')
        await page.getByRole('link', { name: 'Add an app' }).click()
        await page.getByLabel('Name', { exact: true }).fill(name)
        await page
            .getByRole('radiogroup', { name: 'PHP version' })
            .getByRole('radio', { name: /^8\.1\.34/ })
            .click()
        await dialog(page).getByRole('button', { name: 'Use PHP 8.1' }).click()
        await page.getByRole('button', { name: 'Add the app' }).click()

        await expect(page.getByRole('heading', { level: 1, name, exact: true })).toBeVisible()
        await expect(toast(page)).toContainText('Application created.')
    })

    test('a ruby app starts at medium, and a size picked by hand is kept', async ({ page }) => {
        await gotoServer(page, 'bench-01')
        await page.getByRole('link', { name: 'Add an app' }).click()

        const language = page.getByLabel('Language')
        const size = page.getByLabel('Size', { exact: true })

        await expect(size).toHaveValue('small')
        await language.selectOption('ruby')
        await expect(size).toHaveValue('medium')
        await language.selectOption('php')
        await expect(size).toHaveValue('small')

        await language.selectOption('ruby')
        await size.selectOption('small')
        await language.selectOption('js')
        await language.selectOption('ruby')
        await expect(size).toHaveValue('small')

        await size.selectOption('large')
        await language.selectOption('static')
        await expect(size).toHaveValue('large')
    })

    test('the version list follows the javascript runtime', async ({ page }) => {
        await gotoServer(page, 'bench-01')
        await page.getByRole('link', { name: 'Add an app' }).click()
        await page.getByLabel('Language').selectOption('js')
        await page.getByLabel('Runtime').selectOption('node')

        await expect(
            page
                .getByRole('radiogroup', { name: 'Node.js version' })
                .getByRole('radio', { name: /^24\.21\.0 recommended/ })
        ).toBeVisible()
    })

    for (const theme of ['light', 'dark'] as const) {
        test(`the end-of-life question has no serious or critical accessibility violations in ${theme}`, async ({
            page
        }) => {
            await useTheme(page, theme)
            await gotoServer(page, 'bench-01')
            await page.getByRole('link', { name: 'Add an app' }).click()
            await page
                .getByRole('radiogroup', { name: 'PHP version' })
                .getByRole('radio', { name: /^8\.1\.34/ })
                .click()
            await expect(dialog(page)).toBeVisible()

            expect(await blockingViolations(page)).toEqual([])
        })
    }
})

test.describe('an app killed for memory', () => {
    test('the app page says which process, its limit and what to do if it repeats', async ({ page }) => {
        await gotoApplication(page, 'bench-01', 'hungry')

        const banner = page.getByTestId('oom-banner')

        await expect(banner).toContainText('The queue process was killed')
        await expect(banner).toContainText('256 MB memory limit')
        await expect(banner).toContainText('more memory than the Small size gives it')
    })
})

test.describe('an app on an end-of-life runtime', () => {
    test('the app page says which version, when support ended and what to change', async ({ page }) => {
        await gotoApplication(page, 'bench-01', 'legacy-api')

        const banner = page.getByTestId('eol-banner')

        await expect(banner).toContainText(
            'This app runs Node.js 20, which stopped getting security fixes on 30 April 2026.'
        )
        await expect(banner).toContainText('Set .nvmrc to 24, then deploy.')
    })
})
