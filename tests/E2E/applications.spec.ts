import type { Page } from '@playwright/test'
import {
    applicationRow,
    blockingViolations,
    captureClipboard,
    clipboardText,
    dialog,
    expect,
    expectWayBack,
    gotoApplication,
    gotoServer,
    gotoServerTab,
    goWayBack,
    isMobile,
    main,
    own,
    pageData,
    tabs,
    test,
    toast,
    useTheme
} from './support/fixtures'

function toggle(page: Page, name: string) {
    return page.getByTestId('smart-toggles').getByRole('switch', { name })
}

function toggleRow(page: Page, key: string) {
    return page.getByTestId(`toggle-${key}`)
}

async function openAddApp(page: Page) {
    await gotoServer(page, 'bench-01')
    await page.getByRole('link', { name: 'Add an app' }).click()
    await expect(page.getByRole('heading', { level: 1, name: 'Add an app' })).toBeVisible()
}

function repositories(page: Page) {
    return page.getByRole('listbox', { name: 'Repositories on monoventech' })
}

function repositorySearch(page: Page) {
    return page.getByRole('combobox', { name: 'Repository', exact: true })
}

function repository(page: Page, name: string) {
    return repositories(page).getByRole('option', { name: new RegExp(`monoventech/${name}`) })
}

function serveCatalog(page: Page) {
    return page.route('**/settings/source-providers/*/repositories', (route) =>
        route.fulfill({
            status: 200,
            json: {
                total: 4,
                truncated: false,
                repositories: [
                    { id: '70000001', full_name: 'monoventech/atlas', private: true, default_branch: 'main' },
                    { id: '70000002', full_name: 'monoventech/ledger', private: true, default_branch: 'main' },
                    { id: '70000003', full_name: 'monoventech/relay', private: true, default_branch: 'trunk' },
                    { id: '70000004', full_name: 'monoventech/storefront', private: false, default_branch: 'main' }
                ]
            }
        })
    )
}

function branchSuggestions(page: Page) {
    return page
        .locator('#repository-branches option')
        .evaluateAll((options) => options.map((option) => (option as HTMLOptionElement).value))
}

function isApplicationStore(url: string, method: string) {
    return method === 'POST' && /\/servers\/[^/]+\/applications$/.test(new URL(url).pathname)
}

function scrollUnderPointer(page: Page, distance: number) {
    return repositories(page).evaluate((listbox, pixels) => {
        let scroller = listbox.parentElement

        while (
            scroller &&
            !(scroller.scrollHeight > scroller.clientHeight && /auto|scroll/.test(getComputedStyle(scroller).overflowY))
        ) {
            scroller = scroller.parentElement
        }

        const target = scroller ?? document.scrollingElement

        target?.scrollBy(0, pixels)
    }, distance)
}

test.describe('an application', () => {
    test('the header names it and says how it is served', async ({ page }) => {
        await gotoApplication(page, 'forge-01', 'atlas')

        await expectWayBack(page, 'forge-01')
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

    test('a rails app that locks sidekiq gets sidekiq instead', async ({ page }) => {
        await gotoApplication(page, 'forge-01', 'ledger')

        await expect(toggle(page, 'Sidekiq')).toBeVisible()
        await expect(toggle(page, 'Queue worker')).toHaveCount(0)
    })

    test('a rails app that locks solid queue is offered it, and not sidekiq', async ({ page }) => {
        await gotoApplication(page, 'bench-01', 'solid-jobs')

        await expect(toggle(page, 'Solid Queue')).not.toBeChecked()
        await expect(page.getByTestId('toggle-solid_queue')).toContainText('bin/jobs')
        await expect(toggle(page, 'Sidekiq')).toHaveCount(0)
    })

    test('a Django app with Celery is offered its worker and beat, and no Laravel or Rails toggles', async ({ page }) => {
        await gotoApplication(page, 'bench-01', 'celery-tasks')

        await expect(page.getByRole('main')).toContainText('Django · Python 3.14')
        await expect(toggle(page, 'Celery worker')).not.toBeChecked()
        await expect(page.getByTestId('toggle-celery_worker')).toContainText('celery -A tasks worker')
        await expect(toggle(page, 'Celery beat')).not.toBeChecked()
        await expect(toggle(page, 'Queue worker')).toHaveCount(0)
        await expect(toggle(page, 'Sidekiq')).toHaveCount(0)
    })

    test('the current release names its commit and who shipped it', async ({ page }) => {
        await gotoApplication(page, 'forge-01', 'atlas')

        const release = page.getByTestId('release-card')

        await expect(release.getByRole('heading', { name: 'Current release' })).toBeVisible()
        await expect(release).toContainText(/\d{8}-\d{6}/)
        await expect(release).toContainText('Ship v2.14.0')
        await expect(release.getByTestId('release-detail')).toHaveText(
            /^9f3c2e1 on main by Maya Lindqvist, (today \d{2}:\d{2}|[A-Z][a-z]{2} \d{1,2})$/
        )
    })

    test('roll back opens the releases page ready to confirm the previous release', async ({ page }) => {
        await gotoApplication(page, 'forge-01', 'atlas')

        await page.getByTestId('release-card').getByRole('link', { name: 'Roll back' }).click()

        await expect(page.getByRole('heading', { level: 1, name: 'Releases · atlas' })).toBeVisible()
        await expect(dialog(page)).toContainText('Roll back to v2.13.2?')
        await dialog(page).getByRole('button', { name: 'Cancel' }).click()
        await expect(dialog(page)).toHaveCount(0)
    })

    test('the way back returns to the server', async ({ page }) => {
        await gotoApplication(page, 'forge-01', 'atlas')

        await goWayBack(page, 'forge-01')

        await expect(page.getByRole('heading', { level: 1, name: 'forge-01' })).toBeVisible()
    })

    test('the environment preview masks secrets', async ({ page }) => {
        await gotoApplication(page, 'forge-01', 'atlas')

        const preview = page.getByTestId('env-preview')

        await expect(preview).toContainText('APP_ENV=production')
        await expect(preview).toContainText('DB_PASSWORD=••••••••')
        await expect(preview).toContainText('redacted in logs')
    })

    test('a public https repository is cloned without a deploy key', async ({ page }, testInfo) => {
        await gotoApplication(page, 'bench-01', own(testInfo, 'scratch'))

        await expect(page.getByTestId('source-card')).toContainText(
            'Cloned over HTTPS without a key, so the repository has to be public.'
        )
        await expect(page.getByRole('button', { name: 'Copy public key' })).toHaveCount(0)
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
        await expectWayBack(page, 'storefront')
    })

    test('an app with a deploy running offers to watch it instead of deploying again', async ({ page }) => {
        await gotoApplication(page, 'forge-02', 'atlas-staging')

        await expect(page.getByRole('button', { name: 'Deploy', exact: true })).toHaveCount(0)
        await page.getByRole('link', { name: 'Watch deploy' }).click()

        await expect(page.getByRole('heading', { level: 1, name: 'Deploying atlas-staging' })).toBeVisible()
        await expectWayBack(page, 'atlas-staging')
    })

    test('deploying opens the deploy screen and returns to the app', async ({ page }, testInfo) => {
        const name = own(testInfo, 'scratch')

        await gotoApplication(page, 'bench-01', name)
        await page.getByRole('button', { name: 'Deploy', exact: true }).click()

        await expect(page.getByRole('heading', { level: 1, name: `Deploying ${name}` })).toBeVisible()
        await expect(toast(page)).toContainText(`Deploying ${name}.`)

        await goWayBack(page, name)
        await expect(page.getByRole('heading', { level: 1, name, exact: true })).toBeVisible()
    })

    test('on a phone the app page keeps deploy, the toggles and the current release', async ({ page }, testInfo) => {
        test.skip(!isMobile(testInfo), 'The compact app page only renders below 640px')

        await gotoApplication(page, 'forge-01', 'atlas')

        await expect(page.getByRole('button', { name: 'Deploy', exact: true })).toBeVisible()
        await expect(page.getByTestId('smart-toggles')).toBeVisible()
        await expect(page.getByTestId('smart-toggles')).toContainText(
            'Turning on Auto deploy deploys the branch if the live release is behind.'
        )
        await expect(page.getByRole('heading', { name: 'Current release' })).toBeVisible()

        const release = await page.getByTestId('release-card').boundingBox()
        const toggles = await page.getByTestId('smart-toggles').boundingBox()

        expect(release?.y ?? Infinity).toBeLessThan(toggles?.y ?? 0)

        for (const hidden of [
            'Now',
            'Settings',
            'Environment',
            'Domains',
            'Source',
            'Live log',
            'Cron jobs',
            'Danger zone'
        ]) {
            await expect(page.getByRole('heading', { name: hidden, exact: true })).toHaveCount(0)
        }

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

        await expect(page.getByTestId('toggle-queue_worker')).toContainText('php artisan queue:work')
        await expect(toggle(page, 'Queue worker')).not.toBeChecked()

        await toggle(page, 'Queue worker').click()
        await expect(toggle(page, 'Queue worker')).toBeChecked()

        await page.reload()
        await expect(toggle(page, 'Queue worker')).toBeChecked()

        await toggle(page, 'Queue worker').click()
        await expect(toggle(page, 'Queue worker')).not.toBeChecked()
    })

    test('a worker being turned on is checked on until the server reports how it started', async ({
        page
    }, testInfo) => {
        await gotoApplication(page, 'bench-01', own(testInfo, 'scratch'))

        const checking = page.waitForRequest((request) => request.headers()['x-inertia-partial-data'] === 'toggles')

        await toggle(page, 'Queue worker').click()
        await expect(toggle(page, 'Queue worker')).toBeChecked()
        await checking

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
            'The app is stopped, so visitors already see one.'
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

        const source = page.getByTestId('source-card')

        await expect(source).toContainText("Cloned with this app's deploy key, Ed25519 and read-only.")
        await expect(source).toContainText('monoventech/atlas')
        await expect(source.getByRole('button', { name: 'Copy public key' })).toBeVisible()

        const html = await pageData(page)

        expect(html).toContain('monoventech/atlas')
        expect(html).not.toContain('BEGIN OPENSSH PRIVATE KEY')
        expect(html).not.toContain('deploy_key_private')
    })

    test('an app picked from GitHub shows how it clones', async ({ page }) => {
        await gotoApplication(page, 'bench-01', 'linked-app')

        const source = page.getByTestId('source-card')

        await expect(source).toContainText(
            'Cloned through the GitHub App with a one-hour, read-only token for this repository. Nothing long-lived is stored on the server.'
        )
        await expect(source).toContainText('monoventech/relay')
        await expect(source).toContainText('Branch trunk')
        await expect(page.getByRole('button', { name: 'Copy public key' })).toHaveCount(0)
        await expect(page.getByTestId('github-banner')).toHaveCount(0)
    })

    test('domains are listed with their certificates', async ({ page }) => {
        await gotoApplication(page, 'forge-01', 'atlas')

        const domains = page.getByTestId('domains-card')

        await expect(domains).toContainText('www.atlas.monoven.io')
        await expect(domains).toContainText('primary')
        await expect(domains).toContainText('alias')
        await expect(domains).toContainText('Certificates renew themselves.')
    })

    test("a domain visitors can't reach says why and what to do", async ({ page }) => {
        await gotoApplication(page, 'forge-01', 'atlas')

        const reach = page.getByTestId('domain-reach')

        await expect(reach).toContainText(
            'atlas.monoven.io points at 203.0.113.7, but nothing reaches this server on ports 80 and 443 from the internet.'
        )
        await expect(reach).toContainText('use IPv6: add an AAAA record for atlas.monoven.io pointing at 2001:db8::7')
        await expect(reach).toContainText('Checked')
    })

    test('the record a reading asks for is ready to copy', async ({ page }) => {
        await captureClipboard(page)
        await gotoApplication(page, 'forge-01', 'atlas')

        const records = page.getByTestId('domain-reach').getByTestId('domain-records')

        await expect(records).toContainText('AAAA')
        await expect(records).toContainText('atlas.monoven.io')
        await records.getByRole('button', { name: 'Copy the AAAA value 2001:db8::7' }).click()

        await expect(records.getByRole('button', { name: 'Copied the AAAA value 2001:db8::7' })).toBeVisible()
        expect(await clipboardText(page)).toBe('2001:db8::7')
    })

    test("Add a domain shows this server's addresses ready to copy, named as the domain is typed", async ({ page }) => {
        await captureClipboard(page)
        await gotoApplication(page, 'forge-01', 'atlas')

        await page.getByRole('button', { name: 'Add domain' }).click()

        const targets = dialog(page).getByTestId('domain-targets')

        await expect(dialog(page)).toContainText(
            'Point the domain at this server with these records, then add it here. The certificate is handled for you.'
        )
        await expect(targets).toContainText('2001:db8::7')
        await expect(targets).toContainText('203.0.113.7')
        await expect(targets).toContainText("The internet can't reach this address on ports 80 and 443 yet.")

        await dialog(page).getByRole('textbox', { name: 'Domain' }).fill('Shop.Example.com')
        await expect(targets).toContainText('shop.example.com')
        await targets.getByRole('button', { name: 'Copy the AAAA value 2001:db8::7' }).click()

        expect(await clipboardText(page)).toBe('2001:db8::7')
    })

    for (const theme of ['light', 'dark'] as const) {
        test(`the server's addresses in Add a domain are accessible in ${theme}`, async ({ page }) => {
            await useTheme(page, theme)
            await gotoApplication(page, 'forge-01', 'atlas')
            await page.getByRole('button', { name: 'Add domain' }).click()
            await expect(dialog(page).getByTestId('domain-targets')).toBeVisible()

            expect(await blockingViolations(page)).toEqual([])
        })
    }

    test('the domains are checked again from outside on request', async ({ page }) => {
        await gotoApplication(page, 'forge-01', 'atlas')

        await page.getByTestId('domain-reach').getByRole('button', { name: 'Check again' }).click()

        await expect(toast(page)).toContainText('Checking from outside. The results show here in a few seconds.')
    })

    test('the releases link opens this app’s releases', async ({ page }) => {
        await gotoApplication(page, 'forge-01', 'atlas')

        await page.getByTestId('release-card').getByRole('link', { name: 'All releases' }).click()

        await expect(page.getByRole('heading', { level: 1, name: 'Releases · atlas' })).toBeVisible()
    })

    test('what changes sits in one row and the settings in rows that line up', async ({ page }) => {
        await gotoApplication(page, 'forge-01', 'atlas')

        const box = async (id: string) => {
            const found = await page.getByTestId(id).boundingBox()

            expect(found, id).not.toBeNull()

            return found ?? { x: 0, y: 0, width: 0, height: 0 }
        }
        const [release, domains, source] = await Promise.all(['release-card', 'domains-card', 'source-card'].map(box))
        const [toggles, environment, cron] = await Promise.all(
            ['smart-toggles', 'environment-card', 'cron-jobs'].map(box)
        )

        for (const card of [domains, source]) {
            expect(card.y).toBeCloseTo(release.y, 0)
            expect(card.height).toBeCloseTo(release.height, 0)
        }

        expect(domains.x).toBeGreaterThan(release.x + release.width)
        expect(source.x).toBeGreaterThan(domains.x + domains.width)
        expect(environment.y).toBeCloseTo(toggles.y, 0)
        expect(environment.height).toBeCloseTo(toggles.height, 0)
        expect(cron.x).toBeCloseTo(toggles.x, 0)
        expect(cron.x + cron.width).toBeCloseTo(environment.x + environment.width, 0)
        expect(cron.y).toBeGreaterThan(toggles.y + toggles.height)
    })

    test('the live log says it is following once the access log is read', async ({ page }) => {
        await page.route('**/applications/*/logs', (route) =>
            route.fulfill({ json: { lines: [{ time: '09:41:02', text: 'GET /invoices 200 in 38ms' }], error: null } })
        )
        await gotoApplication(page, 'forge-01', 'atlas')

        await expect(page.getByTestId('live-log')).toContainText('09:41:02 GET /invoices 200 in 38ms')
        await expect(page.getByTestId('live-log-card')).toContainText('Following')
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
        await expect(dialog(page)).toContainText(
            'Point the domain at the server first: an A record for its IPv4 address, or an AAAA record for IPv6. Once the app is live, Coresmith checks the domain from outside and shows the record it needs. The certificate is handled for you.'
        )
        await expect(dialog(page).getByTestId('domain-targets')).toHaveCount(0)
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
        await expect(dialog(page)).toContainText('Its releases stay on disk for 7 days in case you change your mind.')
        await dialog(page).getByRole('button', { name: 'Remove the app' }).click()

        await expect(page.getByRole('heading', { level: 1, name: 'bench-01' })).toBeVisible()
        await expect(toast(page)).toContainText('Removed.')
        await expect(applicationRow(page, name)).toHaveCount(0)
    })
})

test.describe('adding an application', () => {
    test("the domain help shows this server's addresses ready to copy, named as the domain is typed", async ({
        page
    }) => {
        await captureClipboard(page)
        await gotoServer(page, 'forge-01')
        await page.getByRole('link', { name: 'Add an app' }).click()

        const targets = page.getByTestId('domain-targets')

        await expect(page.getByRole('main')).toContainText(
            'Point the domain at this server with these records. The certificate is issued on the first visit.'
        )
        await page.getByLabel('Domain').fill('shop.example.com')
        await expect(targets).toContainText('shop.example.com')
        await targets.getByRole('button', { name: 'Copy the AAAA value 2001:db8::7' }).click()

        expect(await clipboardText(page)).toBe('2001:db8::7')
    })

    test("a server Coresmith hasn't read yet keeps the general domain advice", async ({ page }) => {
        await openAddApp(page)

        await expect(page.getByRole('main')).toContainText(
            'Point the domain at the server first: an A record for its IPv4 address, or an AAAA record for IPv6. Once the app is live, Coresmith checks the domain from outside and shows the record it needs.'
        )
        await expect(page.getByTestId('domain-targets')).toHaveCount(0)
    })

    test('the server offers to add an app', async ({ page }) => {
        await gotoServer(page, 'bench-01')

        await page.getByRole('link', { name: 'Add an app' }).click()

        await expect(page.getByRole('heading', { level: 1, name: 'Add an app' })).toBeVisible()
        await expect(page.getByRole('main')).toContainText('What a deploy does')
        await expect(page.getByRole('main')).toContainText(
            'Clone release, fetched with a one-hour token or a read-only deploy key'
        )
    })

    test('an app is created and opens on its own page', async ({ page }) => {
        const name = `e2e${Date.now().toString(36)}`

        await gotoServer(page, 'bench-01')
        await page.getByRole('link', { name: 'Add an app' }).click()

        await page.getByLabel('Source', { exact: true }).selectOption({ label: 'A Git URL' })
        await page.getByLabel('Name', { exact: true }).fill(name)
        await page.getByLabel('Language').selectOption('js')
        await expect(page.getByLabel('Framework')).toHaveValue('sveltekit')
        await page.getByLabel('Domain').fill(`${name}.bench.monoven.io`)
        await page.getByLabel('Project', { exact: true }).selectOption({ label: 'Storefront › Staging' })
        await page.getByRole('button', { name: 'Add the app' }).click()

        await expect(page.getByRole('heading', { level: 1, name, exact: true })).toBeVisible()
        await expect(toast(page)).toContainText('App added.')
        await expect(page.getByTestId('app-project')).toContainText('Storefront › Staging')
    })

    test('a server with no room for another app says so and keeps the form as it was', async ({ page }) => {
        await gotoServer(page, 'full-01')
        await page.getByRole('link', { name: 'Add an app' }).click()

        await page.getByLabel('Source', { exact: true }).selectOption({ label: 'A Git URL' })
        await page.getByLabel('Name', { exact: true }).fill('one-too-many')
        await page.getByLabel('Language').selectOption('js')
        await page.getByLabel('Domain').fill('one-too-many.bench.monoven.io')
        await page.getByRole('button', { name: 'Add the app' }).click()

        await expect(toast(page)).toContainText("There's no room for another app on full-01.")
        await expect(toast(page)).toContainText("A removed app's ports free up after 7 days.")
        await expect(page.getByRole('heading', { level: 1, name: 'Add an app' })).toBeVisible()
        await expect(page.getByLabel('Name', { exact: true })).toHaveValue('one-too-many')
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
        await expect(page.getByRole('main')).toContainText(
            "Used as long as the repository's own version files allow it. If they don't, the deploy stops and says why."
        )
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
        await page.getByLabel('Source', { exact: true }).selectOption({ label: 'A Git URL' })
        await page.getByLabel('Name', { exact: true }).fill(name)
        await page
            .getByRole('radiogroup', { name: 'PHP version' })
            .getByRole('radio', { name: /^8\.1\.34/ })
            .click()
        await dialog(page).getByRole('button', { name: 'Use PHP 8.1' }).click()
        await page.getByRole('button', { name: 'Add the app' }).click()

        await expect(page.getByRole('heading', { level: 1, name, exact: true })).toBeVisible()
        await expect(toast(page)).toContainText('App added.')
    })

    test('the JavaScript frameworks come from the presets, AdonisJS and Angular among them', async ({ page }) => {
        await openAddApp(page)
        await page.getByLabel('Language').selectOption('js')

        const framework = page.getByLabel('Framework')

        await expect(framework).toHaveValue('sveltekit')
        await framework.selectOption('adonisjs')
        await expect(framework).toHaveValue('adonisjs')
        await framework.selectOption('angular')
        await expect(framework).toHaveValue('angular')
    })

    test('Python offers its frameworks and versions, and no web root', async ({ page }) => {
        await openAddApp(page)
        await page.getByLabel('Language').selectOption('python')

        const framework = page.getByLabel('Framework')

        await expect(framework).toHaveValue('django')
        await expect(framework.locator('option')).toHaveText(['Django', 'FastAPI', 'Starlette', 'Flask', 'Other framework'])
        await framework.selectOption('fastapi')
        await expect(framework).toHaveValue('fastapi')
        await expect(page.getByLabel('Web root')).toHaveCount(0)
        await expect(page.getByRole('radiogroup', { name: 'Python version' }).getByRole('radio', { name: /^3\.14\./ })).toBeVisible()
    })

    test('the web root is asked only of PHP apps and static sites, which serve files from it', async ({ page }) => {
        await openAddApp(page)

        const language = page.getByLabel('Language')
        const webRoot = page.getByLabel('Web root')

        await expect(webRoot).toHaveValue('/public')

        for (const proxied of ['js', 'ruby', 'python']) {
            await language.selectOption(proxied)
            await expect(webRoot).toHaveCount(0)
        }

        await language.selectOption('static')
        await expect(webRoot).toBeVisible()
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

test.describe('adding an application from GitHub', () => {
    test('repositories from the connected installation are offered and searchable', async ({ page }) => {
        await openAddApp(page)

        const source = page.getByLabel('Source', { exact: true })

        await expect(source.locator('option:checked')).toHaveText('GitHub · monoventech')
        await expect(source.locator('option')).toHaveText(['GitHub · monoventech', 'A Git URL'])
        await expect(repositorySearch(page)).toHaveAttribute('placeholder', 'Search repositories')
        await expect(repositorySearch(page)).toHaveAccessibleDescription('Search repositories on monoventech')
        await expect(repositories(page).getByRole('option')).toHaveCount(4)
        await expect(repositories(page).getByRole('option', { name: /monoventech\/storefront/ })).not.toContainText(
            'private'
        )

        await repositorySearch(page).fill('led')

        await expect(repositories(page).getByRole('option')).toHaveCount(1)
        await expect(repositories(page).getByRole('option')).toContainText('monoventech/ledger')
        await expect(repositories(page).getByRole('option').getByText('private', { exact: true })).toBeVisible()
    })

    test('picking a repository fills its default branch and suggests the others', async ({ page }) => {
        await openAddApp(page)

        const branch = page.getByLabel('Branch', { exact: true })
        const relay = repositories(page).getByRole('option', { name: /monoventech\/relay/ })

        await expect(branch).toHaveValue('main')
        await relay.click()

        await expect(relay).toHaveAttribute('aria-selected', 'true')
        await expect(branch).toHaveValue('trunk')
        await expect(branch).toHaveAttribute('list', 'repository-branches')
        await expect.poll(() => branchSuggestions(page)).toEqual(['trunk', 'release/2.0'])
    })

    test('a branch typed by hand is kept when a repository is picked', async ({ page }) => {
        await serveCatalog(page)
        await openAddApp(page)

        const branch = page.getByLabel('Branch', { exact: true })

        await branch.fill('release/2.0')
        await repositories(page)
            .getByRole('option', { name: /monoventech\/relay/ })
            .click()

        await expect.poll(() => branchSuggestions(page)).toEqual(['trunk', 'release/2.0'])
        await expect(branch).toHaveValue('release/2.0')
    })

    test('an app picked from GitHub is created', async ({ page }, testInfo) => {
        const name = `${own(testInfo, 'picked')}-${Date.now().toString(36)}`

        await openAddApp(page)
        await page.getByLabel('Name', { exact: true }).fill(name)
        await repositories(page)
            .getByRole('option', { name: /monoventech\/ledger/ })
            .click()
        await expect(page.getByLabel('Branch', { exact: true })).toHaveValue('main')

        const posted = page.waitForRequest((request) => isApplicationStore(request.url(), request.method()))

        await page.getByRole('button', { name: 'Add the app' }).click()

        const sent = (await posted).postDataJSON()

        expect(sent.repository_id).toBe('70000002')
        expect(sent.source_provider_id).toMatch(/^[0-9a-z]{26}$/i)
        expect(sent).not.toHaveProperty('repository_url')
        await expect(page.getByRole('heading', { level: 1, name, exact: true })).toBeVisible()
        await expect(toast(page)).toContainText('App added.')
    })

    test('a Git URL still works without picking', async ({ page }, testInfo) => {
        const name = `${own(testInfo, 'by-url')}-${Date.now().toString(36)}`

        await serveCatalog(page)
        await openAddApp(page)
        await page.getByLabel('Source', { exact: true }).selectOption({ label: 'A Git URL' })

        const url = page.getByLabel('Repository', { exact: true })

        await expect(page.getByRole('listbox', { name: /repositories/i })).toHaveCount(0)
        await expect(url).toBeVisible()
        await expect(url).toHaveAttribute('placeholder', 'git@github.com:you/atlas.git')

        await page.getByLabel('Name', { exact: true }).fill(name)
        await url.fill('https://github.com/monoventech/storefront.git')

        const posted = page.waitForRequest((request) => isApplicationStore(request.url(), request.method()))

        await page.getByRole('button', { name: 'Add the app' }).click()

        const sent = (await posted).postDataJSON()

        expect(sent.repository_url).toBe('https://github.com/monoventech/storefront.git')
        expect(sent).not.toHaveProperty('source_provider_id')
        expect(sent).not.toHaveProperty('repository_id')
        await expect(page.getByRole('heading', { level: 1, name, exact: true })).toBeVisible()
        await expect(toast(page)).toContainText('App added.')
    })

    test('adding an app from GitHub without picking a repository asks for one', async ({ page }) => {
        await openAddApp(page)
        await expect(repositories(page).getByRole('option')).toHaveCount(4)

        await page.getByLabel('Name', { exact: true }).fill('unpicked')
        await page.getByRole('button', { name: 'Add the app' }).click()

        await expect(page.getByRole('main')).toContainText('Pick a repository from the list.')
        await expect(repositorySearch(page)).toHaveAttribute('aria-invalid', 'true')
        await expect(page.getByRole('heading', { level: 1, name: 'Add an app' })).toBeVisible()
    })

    test('nothing matching says so', async ({ page }) => {
        await serveCatalog(page)
        await openAddApp(page)
        await expect(repositories(page).getByRole('option')).toHaveCount(4)

        await repositorySearch(page).fill('zzz')

        await expect(page.getByRole('main')).toContainText('No repository matches "zzz".')
        await expect(repositorySearch(page)).toHaveAttribute('aria-expanded', 'false')
    })

    test('the keyboard moves through repositories and enter picks one', async ({ page }, testInfo) => {
        await serveCatalog(page)
        await openAddApp(page)

        const search = repositorySearch(page)
        const option = (name: string) => repositories(page).getByRole('option', { name: new RegExp(name) })

        const stored: string[] = []

        page.on('request', (request) => {
            if (isApplicationStore(request.url(), request.method())) {
                stored.push(request.url())
            }
        })

        await page.getByLabel('Name', { exact: true }).fill(own(testInfo, 'keyed'))
        await expect(repositories(page).getByRole('option')).toHaveCount(4)
        await search.focus()
        await page.keyboard.press('ArrowDown')
        await page.keyboard.press('ArrowDown')

        await expect(search).toHaveAttribute('aria-activedescendant', (await option('ledger').getAttribute('id')) ?? '')

        const branches = page.waitForResponse((response) =>
            new URL(response.url()).pathname.endsWith('/repositories/70000002/branches')
        )

        await page.keyboard.press('Enter')
        await branches

        expect(stored).toEqual([])
        await expect(option('ledger')).toHaveAttribute('aria-selected', 'true')
        await expect(option('atlas')).toHaveAttribute('aria-selected', 'false')
        await expect(page.locator('input[name="repository_id"]')).toHaveValue('70000002')
        await expect(page.getByRole('heading', { level: 1, name: 'Add an app' })).toBeVisible()

        await page.keyboard.press('ArrowUp')
        await page.keyboard.press('ArrowUp')

        await expect(search).toHaveAttribute(
            'aria-activedescendant',
            (await option('storefront').getAttribute('id')) ?? ''
        )

        await page.keyboard.press('ArrowDown')

        await expect(search).toHaveAttribute('aria-activedescendant', (await option('atlas').getAttribute('id')) ?? '')
    })

    test('a pointer resting over the list does not move the keyboard highlight', async ({ page }) => {
        await serveCatalog(page)
        await openAddApp(page)

        const search = repositorySearch(page)
        const option = (name: string) => repositories(page).getByRole('option', { name: new RegExp(name) })

        await expect(repositories(page).getByRole('option')).toHaveCount(4)
        await search.focus()

        const atlas = await option('atlas').boundingBox()

        await page.mouse.move((atlas?.x ?? 0) + 40, (atlas?.y ?? 0) + (atlas?.height ?? 0) / 2)
        await scrollUnderPointer(page, 88)
        await page.keyboard.press('ArrowDown')

        await expect(search).toHaveAttribute('aria-activedescendant', (await option('atlas').getAttribute('id')) ?? '')
    })

    test('the list loads, and one GitHub would not send says why and can be fetched again', async ({ page }) => {
        let answer: () => void = () => undefined
        const answered = new Promise<void>((resolve) => (answer = resolve))

        await page.route('**/settings/source-providers/*/repositories', async (route) => {
            await answered
            await route.fulfill({
                status: 502,
                json: {
                    message:
                        'GitHub did not send the repository list. This GitHub connection was made through an app Coresmith no longer has. Connect GitHub again from Settings, Git.'
                }
            })
        })
        await openAddApp(page)

        await expect(page.getByRole('main')).toContainText('Loading repositories from GitHub…')

        answer()

        await expect(page.getByRole('main')).toContainText(
            'GitHub did not send the repository list. This GitHub connection was made through an app Coresmith no longer has. Connect GitHub again from Settings, Git.'
        )
        await expect(page.getByRole('listbox', { name: /repositories/i })).toHaveCount(0)

        await page.unroute('**/settings/source-providers/*/repositories')
        await page.getByRole('button', { name: 'Try again' }).click()

        await expect(repositories(page).getByRole('option')).toHaveCount(4)
        await expect(page.getByRole('button', { name: 'Try again' })).toHaveCount(0)
    })

    test('an installation that shares no repositories says where to choose some', async ({ page }) => {
        await page.route('**/settings/source-providers/*/repositories', (route) =>
            route.fulfill({ status: 200, json: { repositories: [], total: 0, truncated: false } })
        )
        await openAddApp(page)

        await expect(page.getByRole('main')).toContainText(
            'The GitHub App can see no repositories on monoventech yet. Choose some under Manage repositories on GitHub.'
        )

        const manage = page.getByRole('link', { name: 'Manage repositories' })

        await expect(manage).toHaveAttribute(
            'href',
            'https://github.com/organizations/monoventech/settings/installations/51842'
        )
        await expect(manage).toHaveAttribute('target', '_blank')
        await expect(manage).toHaveAttribute('rel', 'noopener noreferrer')
    })

    test('a list cut short by GitHub says how much is shown', async ({ page }) => {
        await page.route('**/settings/source-providers/*/repositories', (route) =>
            route.fulfill({
                status: 200,
                json: {
                    total: 2345,
                    truncated: true,
                    repositories: [
                        { id: '70000001', full_name: 'monoventech/atlas', private: true, default_branch: 'main' },
                        { id: '70000002', full_name: 'monoventech/ledger', private: true, default_branch: 'main' },
                        { id: '70000003', full_name: 'monoventech/relay', private: true, default_branch: 'trunk' }
                    ]
                }
            })
        )
        await openAddApp(page)

        await expect(repositories(page).getByRole('option')).toHaveCount(3)
        await expect(page.getByRole('main')).toContainText(
            'Showing the first 3 of 2,345 repositories. Use a Git URL for one that is not listed.'
        )
    })

    test('a branch list that arrives after the source changed is ignored', async ({ page }) => {
        let answer: () => void = () => undefined
        const answered = new Promise<void>((resolve) => (answer = resolve))
        let settle: () => void = () => undefined
        const settled = new Promise<void>((resolve) => (settle = resolve))

        await page.route('**/repositories/70000003/branches', async (route) => {
            await answered
            await route
                .fulfill({ status: 200, json: { branches: ['trunk', 'release/2.0'], default: 'trunk' } })
                .catch(() => undefined)
            settle()
        })
        await serveCatalog(page)
        await openAddApp(page)

        const branch = page.getByLabel('Branch', { exact: true })

        await repository(page, 'relay').click()
        await expect(branch).toHaveValue('trunk')
        await page.getByLabel('Source', { exact: true }).selectOption({ label: 'A Git URL' })
        await expect(page.getByLabel('Repository', { exact: true })).toBeVisible()
        await expect(branch).toHaveValue('main')
        expect(await branchSuggestions(page)).toEqual([])

        answer()
        await settled
        await page.evaluate(() => new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve))))

        await expect(branch).toHaveValue('main')
        expect(await branchSuggestions(page)).toEqual([])
    })

    test('changing the source drops a branch a pick filled and keeps one typed by hand', async ({ page }) => {
        await serveCatalog(page)
        await openAddApp(page)

        const branch = page.getByLabel('Branch', { exact: true })
        const source = page.getByLabel('Source', { exact: true })

        await repository(page, 'relay').click()
        await expect.poll(() => branchSuggestions(page)).toEqual(['trunk', 'release/2.0'])
        await expect(branch).toHaveValue('trunk')

        await source.selectOption({ label: 'A Git URL' })

        await expect(branch).toHaveValue('main')
        expect(await branchSuggestions(page)).toEqual([])

        await source.selectOption({ label: 'GitHub · monoventech' })
        await branch.fill('release/2.0')
        await repository(page, 'relay').click()
        await expect.poll(() => branchSuggestions(page)).toEqual(['trunk', 'release/2.0'])
        await source.selectOption({ label: 'A Git URL' })

        await expect(branch).toHaveValue('release/2.0')
    })

    test('a GitHub connection that is gone when the form comes back still says why', async ({ page }) => {
        await serveCatalog(page)
        await openAddApp(page)
        await page.getByLabel('Name', { exact: true }).fill('gone-connection')
        await repository(page, 'ledger').click()
        await page.route(
            (url) => /\/servers\/[^/]+\/applications$/.test(url.pathname),
            async (route) => {
                if (route.request().method() !== 'POST') {
                    await route.continue()

                    return
                }

                const sent = route.request().postDataJSON()
                const response = await route.fetch({
                    postData: { ...sent, source_provider_id: '01jzzzzzzzzzzzzzzzzzzzzzzz' }
                })
                const returned = await response.json()

                await route.fulfill({
                    response,
                    json: {
                        ...returned,
                        props: { ...returned.props, github: { ...returned.props.github, connections: [] } }
                    }
                })
            }
        )

        await page.getByRole('button', { name: 'Add the app' }).click()

        await expect(page.getByLabel('Repository', { exact: true })).toBeVisible()
        await expect(page.getByLabel('Source', { exact: true })).toHaveCount(0)
        await expect(page.getByRole('main')).toContainText(
            "That GitHub connection isn't available. Pick another one, or connect GitHub again in Git settings."
        )
    })

    test('picking with the mouse keeps the keyboard on the search', async ({ page }) => {
        await serveCatalog(page)
        await openAddApp(page)

        const search = repositorySearch(page)

        await search.focus()
        await repository(page, 'ledger').click()

        await expect(search).toBeFocused()
        await page.keyboard.press('ArrowDown')

        await expect(search).toHaveAttribute(
            'aria-activedescendant',
            (await repository(page, 'relay').getAttribute('id')) ?? ''
        )
    })

    test('tapping a repository puts the on-screen keyboard away', async ({ page, hasTouch }) => {
        test.skip(!hasTouch, 'Only the phone projects have a touch screen to tap')

        await serveCatalog(page)
        await openAddApp(page)

        const search = repositorySearch(page)

        await search.focus()
        await repository(page, 'ledger').tap()

        await expect(repository(page, 'ledger')).toHaveAttribute('aria-selected', 'true')
        await expect(search).not.toBeFocused()
    })

    test('a branch list GitHub will not send is explained in plain words', async ({ page }) => {
        const answers = [
            {
                status: 404,
                json: {
                    message:
                        'GitHub does not give Coresmith access to that repository. Pick another, or add it under Manage repositories on GitHub.'
                }
            },
            {
                status: 404,
                json: { message: 'No query results for model [App\\Models\\SourceProvider] 01jzzzzzzzzzzzzzzzzzzzzzzz' }
            },
            { status: 502, json: { message: 'GitHub did not send the branch list. GitHub did not answer.' } }
        ]
        let next = 0

        await page.route('**/repositories/*/branches', (route) => route.fulfill(answers[next++] ?? answers[0]))
        await serveCatalog(page)
        await openAddApp(page)

        const main = page.getByRole('main')

        await repository(page, 'atlas').click()
        await expect(main).toContainText(
            'GitHub does not give Coresmith access to that repository. Pick another, or add it under Manage repositories on GitHub.'
        )

        await repository(page, 'ledger').click()
        await expect(main).toContainText('Coresmith could not load the branch list. Type the branch name instead.')
        await expect(main).not.toContainText('No query results')

        await repository(page, 'relay').click()
        await expect(main).toContainText('Coresmith could not load the branch list. Type the branch name instead.')
        await expect(main).not.toContainText('GitHub did not send the branch list')
    })

    test('a repository the connection can no longer deploy says who must connect again', async ({ page }) => {
        const refusal =
            'ada can no longer read monoventech/atlas on GitHub, so this connection cannot deploy it. Someone who can read it connects GitHub again from Settings, Git.'

        await page.route('**/repositories/*/branches', (route) =>
            route.fulfill({ status: 409, json: { message: refusal } })
        )
        await serveCatalog(page)
        await openAddApp(page)

        await repository(page, 'atlas').click()

        await expect(page.getByRole('main')).toContainText(refusal)
    })

    for (const theme of ['light', 'dark'] as const) {
        test(`a connection whose app was removed from the panel is refused under Source, in ${theme}`, async ({
            page
        }) => {
            await useTheme(page, theme)

            try {
                await page.goto('/stray/dashboard')
                await page.getByTestId('server-card').getByRole('link', { name: 'stray-01', exact: true }).click()
                await expect(page.getByRole('heading', { level: 1, name: 'stray-01' })).toBeVisible()
                await page.getByRole('link', { name: 'Add an app' }).click()
                await expect(page.getByRole('heading', { level: 1, name: 'Add an app' })).toBeVisible()

                await page.getByLabel('Name', { exact: true }).fill('harbor')
                await page
                    .getByRole('listbox', { name: 'Repositories on stray-works' })
                    .getByRole('option', { name: /stray-works\/harbor/ })
                    .click()
                await expect(page.getByLabel('Branch', { exact: true })).toHaveValue('main')
                await page.getByRole('button', { name: 'Add the app' }).click()

                const source = page.locator('div:has(> label[for="source"])')

                await expect(page.getByLabel('Source', { exact: true })).toHaveAttribute('aria-invalid', 'true')
                await expect(source.getByRole('alert')).toHaveText(
                    'That GitHub connection was made through an app Coresmith no longer has. Pick another one, or connect GitHub again in Git settings.'
                )
                await expect(page.getByRole('heading', { level: 1, name: 'Add an app' })).toBeVisible()
                expect(await blockingViolations(page)).toEqual([])
            } finally {
                await page.goto('/monoven/dashboard')
            }
        })

        test(`a team without GitHub gets the URL field and the way to connect GitHub, in ${theme}`, async ({
            page
        }) => {
            await useTheme(page, theme)

            try {
                await page.goto('/acme/dashboard')
                await page.getByTestId('server-card').getByRole('link', { name: 'acme-web', exact: true }).click()
                await expect(page.getByRole('heading', { level: 1, name: 'acme-web' })).toBeVisible()
                await page.getByRole('link', { name: 'Add an app' }).click()
                await expect(page.getByRole('heading', { level: 1, name: 'Add an app' })).toBeVisible()

                await expect(page.getByLabel('Source', { exact: true })).toHaveCount(0)
                await expect(page.getByLabel('Repository', { exact: true })).toBeVisible()
                await expect(page.getByRole('main')).toContainText(
                    'Connect GitHub in Settings, Git to pick a private repository from a list.'
                )
                await expect(page.getByRole('link', { name: 'Settings, Git' })).toHaveAttribute(
                    'href',
                    /\/acme\/settings\/git$/
                )
                expect(await blockingViolations(page)).toEqual([])
            } finally {
                await page.goto('/monoven/dashboard')
            }
        })
    }
})

test.describe('an app whose processes did not start', () => {
    function header(page: Page, name: string) {
        return page.getByRole('heading', { level: 1, name, exact: true }).locator('..')
    }

    test('a release that went live but did not start says the app failed and why, not that it is serving', async ({
        page
    }) => {
        await gotoApplication(page, 'bench-01', 'dev-mode')

        const banner = page.getByTestId('failed-banner')

        await expect(header(page, 'dev-mode')).toContainText('Failed')
        await expect(header(page, 'dev-mode')).not.toContainText('Live')
        await expect(banner).toContainText('The last deploy went live but did not finish.')
        await expect(banner).toContainText("The web process didn't start (activating).")
        await expect(banner).toContainText('Set RAILS_ENV=production in its environment variables, then deploy again.')
        await expect(banner).not.toContainText('is serving')

        await banner.getByRole('link', { name: 'Read the log' }).click()

        await expect(page.getByTestId('failure-reason')).toContainText('The processes did not start.')
        await expect(page.getByTestId('failure-reason')).toContainText("The web process didn't start (activating).")
        await expect(page.getByTestId('deployment-log')).toContainText('cannot load such file -- debug/prelude')
    })

    test('a worker that did not start after going live leaves the app live, and says which worker and why', async ({
        page
    }) => {
        await gotoApplication(page, 'bench-01', 'no-sidekiq')

        const banner = page.getByTestId('failed-banner')

        await expect(header(page, 'no-sidekiq')).toContainText('Live')
        await expect(header(page, 'no-sidekiq')).not.toContainText('Failed')
        await expect(banner).toContainText('The last deploy went live but did not finish.')
        await expect(banner).toContainText("The sidekiq process didn't start (activating).")
        await expect(toggle(page, 'Sidekiq')).toBeChecked()
        await expect(toggle(page, 'Sidekiq')).toBeEnabled()
    })

    test('a rollback that did not start says so, with the reason and what to do', async ({ page }) => {
        await gotoApplication(page, 'bench-01', 'failed-rollback')

        const banner = page.getByTestId('rollback-failed-banner')

        await expect(header(page, 'failed-rollback')).toContainText('Failed')
        await expect(banner).toContainText('The rollback did not start the app.')
        await expect(banner).toContainText(/releases\/\d{8}-\d{6} is live again, but its processes did not start\./)
        await expect(banner).toContainText(
            "The web process didn't start (activating). Port 8310 is already taken by another process."
        )
        await expect(page.getByTestId('failed-banner')).toHaveCount(0)
    })

    test('a rollback where only a worker did not start keeps the app live and names the worker', async ({ page }) => {
        await gotoApplication(page, 'bench-01', 'rollback-sidekiq')

        const banner = page.getByTestId('rollback-failed-banner')

        await expect(header(page, 'rollback-sidekiq')).toContainText('Live')
        await expect(header(page, 'rollback-sidekiq')).not.toContainText('Failed')
        await expect(banner).toContainText('Sidekiq did not start after the rollback.')
        await expect(banner).toContainText(/releases\/\d{8}-\d{6} is live again, but Sidekiq did not start\./)
        await expect(banner).toContainText("The sidekiq process didn't start (activating).")
        await expect(banner).not.toContainText('The rollback did not start the app.')
    })

    test('a worker that did not start says why, and its toggle is off again', async ({ page }) => {
        await gotoApplication(page, 'bench-01', 'stuck-worker')

        const banner = page.getByTestId('toggle-failed-banner')

        await expect(banner).toHaveCount(1)
        await expect(banner).toContainText('Solid Queue did not start.')
        await expect(banner).toContainText(
            'The entry point was not found. Check the build produced the file the process starts, then deploy again.'
        )
        await expect(toggle(page, 'Solid Queue')).not.toBeChecked()
    })
})

test.describe('an app that follows its branch', () => {
    test('auto deploy says it polls and why when GitHub cannot reach the panel', async ({ page }, testInfo) => {
        await gotoApplication(page, 'bench-01', own(testInfo, 'linked'))

        const row = page.getByTestId('toggle-auto_deploy')

        await expect(toggle(page, 'Auto deploy')).not.toBeChecked()
        await expect(row).toContainText('Deploy trunk whenever it changes')

        await toggle(page, 'Auto deploy').click()

        await expect(toast(page)).toContainText('Saved. Auto deploy is on, trunk deploys whenever it changes.')
        await expect(toggle(page, 'Auto deploy')).toBeChecked()
        await expect(row).toContainText('Checks trunk for new commits every minute.')
        await expect(row).toContainText("GitHub can't reach 127.0.0.1, because it only answers on this machine.")

        await toggle(page, 'Auto deploy').click()

        await expect(toast(page)).toContainText("Saved. Auto deploy is off. Deploy from this page when you're ready.")
        await expect(toggle(page, 'Auto deploy')).not.toBeChecked()
        await expect(row).toContainText('Deploy trunk whenever it changes')
    })

    test('an app without a GitHub link checks from its server', async ({ page }, testInfo) => {
        await gotoApplication(page, 'bench-01', own(testInfo, 'scratch'))

        const row = page.getByTestId('toggle-auto_deploy')

        await expect(row).toContainText('Deploy main whenever it changes')

        await toggle(page, 'Auto deploy').click()

        await expect(toggle(page, 'Auto deploy')).toBeChecked()
        await expect(row).toContainText('Checks main for new commits every 5 minutes from bench-01.')
        await expect(row).toContainText(
            'This app is not linked to a GitHub connection, so pushes are not delivered here.'
        )

        await toggle(page, 'Auto deploy').click()

        await expect(toggle(page, 'Auto deploy')).not.toBeChecked()
        await expect(row).toContainText('Deploy main whenever it changes')
    })

    test('an app with auto deploy on shows its mode', async ({ page }) => {
        await gotoApplication(page, 'bench-01', 'pushing-app')

        const row = page.getByTestId('toggle-auto_deploy')

        await expect(toggle(page, 'Auto deploy')).toBeChecked()
        await expect(row).toContainText('Checks trunk for new commits every minute.')
        await expect(row).toContainText("GitHub can't reach 127.0.0.1, because it only answers on this machine.")
    })
})

test.describe('an app whose GitHub connection is gone', () => {
    test('an app whose GitHub connection was removed says what still works', async ({ page }) => {
        await gotoApplication(page, 'bench-01', 'orphaned-app')

        const banner = page.getByTestId('github-banner')

        await expect(banner).toContainText(
            'This app clones monoventech/relay through GitHub, and that connection was removed.'
        )
        await expect(banner).toContainText(
            'Public repositories still deploy. Private ones need GitHub connected again.'
        )
        await expect(page.getByTestId('source-card')).toContainText(
            'Cloned through GitHub, which needs connecting again.'
        )
        await expect(page.getByTestId('source-card')).not.toContainText('one-hour, read-only token')

        await banner.getByRole('link', { name: 'Open Git settings' }).click()

        await page.waitForURL(/\/settings\/git$/)
        await expect(tabs(page, 'Settings').getByRole('link', { name: 'Git', exact: true })).toHaveAttribute(
            'aria-current',
            'page'
        )
    })

    test('an app whose GitHub installation is suspended says so', async ({ page }) => {
        await page.route('**/servers/*/applications/*', async (route) => {
            if (route.request().headers()['x-inertia'] === undefined) {
                await route.continue()

                return
            }

            const response = await route.fetch()
            const body = await response.json()

            body.props.application.github = { ...body.props.application.github, problem: 'suspended' }
            await route.fulfill({ response, json: body })
        })
        await gotoApplication(page, 'bench-01', 'orphaned-app')

        await expect(page.getByTestId('github-banner')).toContainText(
            'This app clones monoventech/relay through GitHub, and that connection is suspended.'
        )
    })

    test('on a phone the banner gives its text the full width and puts the link under it', async ({
        page
    }, testInfo) => {
        test.skip(!isMobile(testInfo), 'The link moves under the text only below 640px')

        await gotoApplication(page, 'bench-01', 'orphaned-app')

        const banner = page.getByTestId('github-banner')
        const text = await banner.getByText('Public repositories still deploy').boundingBox()
        const link = await banner.getByRole('link', { name: 'Open Git settings' }).boundingBox()

        expect(text).not.toBeNull()
        expect(link).not.toBeNull()
        expect(link?.y ?? 0).toBeGreaterThanOrEqual((text?.y ?? 0) + (text?.height ?? 0))
        expect(Math.abs((link?.x ?? 0) - (text?.x ?? 0))).toBeLessThanOrEqual(1)
    })
})

test.describe('action banners on a phone', () => {
    test.skip(({ isMobile }) => !isMobile, 'The phone layout and tap sizes apply only below 640px')

    const banners = [
        { server: 'forge-01', app: 'storefront', id: 'failed-banner', role: 'link', action: 'Read the log' },
        { server: 'bench-01', app: 'retired-blog', id: 'offline-banner', role: 'button', action: 'Start it' },
        { server: 'bench-01', app: 'orphaned-app', id: 'github-banner', role: 'link', action: 'Open Git settings' }
    ] as const

    for (const { server, app, id, role, action } of banners) {
        test(`the ${id} gives its text the full width and puts an easy-to-tap action under it`, async ({ page }) => {
            await gotoApplication(page, server, app)

            const banner = page.getByTestId(id)
            const text = await banner.locator('span.flex-1').first().boundingBox()
            const control = await banner.getByRole(role, { name: action }).boundingBox()

            expect(text).not.toBeNull()
            expect(control).not.toBeNull()
            expect(control?.y ?? 0).toBeGreaterThanOrEqual((text?.y ?? 0) + (text?.height ?? 0))
            expect(Math.abs((control?.x ?? 0) - (text?.x ?? 0))).toBeLessThanOrEqual(1)
            expect(control?.height ?? 0).toBeGreaterThanOrEqual(44)
        })
    }

    test('the source card stays off a phone', async ({ page }) => {
        await gotoApplication(page, 'bench-01', 'linked-app')

        await expect(page.getByTestId('source-card')).toBeHidden()
    })
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

test.describe('what the repository installs', () => {
    test('a laravel app with horizon says so on its worker row', async ({ page }, testInfo) => {
        const name = own(testInfo, 'realtime')

        await gotoApplication(page, 'bench-01', name)

        await expect(toggleRow(page, 'queue_worker')).toContainText('Detected: laravel/horizon')
        await expect(toggle(page, 'Queue worker')).toBeChecked()
    })

    test('reverb turns on with the next deploy', async ({ page }, testInfo) => {
        const name = own(testInfo, 'realtime')
        const row = toggleRow(page, 'reverb')

        await gotoApplication(page, 'bench-01', name)

        await expect(row).toContainText('Reverb websockets')
        await expect(row).toContainText(/Available\. Turning it on gives it port 91[2-6]2 from the next deploy/)

        await toggle(page, 'Reverb websockets').click()

        await expect(toast(page)).toContainText('Saved. Reverb websockets starts with the next deploy.')
        await expect(row).toContainText(`Starts with the next deploy, on port`)
        await expect(row).toContainText(`behind wss://${name}.monoven.io/app`)
        await expect(toggle(page, 'Reverb websockets')).toBeChecked()
    })

    test('inertia ssr turns on with the next deploy', async ({ page }, testInfo) => {
        const row = toggleRow(page, 'inertia_ssr')

        await gotoApplication(page, 'bench-01', own(testInfo, 'realtime'))

        await expect(page.getByTestId('smart-toggles').locator('[data-testid^="toggle-"]').first()).toHaveAttribute(
            'data-testid',
            'toggle-inertia_ssr'
        )
        await expect(row).toContainText(
            'Detected from composer.json and package.json · turning it on gives it a port from the next deploy'
        )

        await toggle(page, 'Inertia SSR').click()

        await expect(toast(page)).toContainText('Saved. Inertia SSR starts with the next deploy.')
        await expect(row).toContainText(/Starts with the next deploy, which builds the SSR bundle · port 137\d\d/)
        await expect(toggle(page, 'Inertia SSR')).toBeChecked()
    })

    test("a toggle that is on says why the release no longer runs it, and one whose package is gone isn't listed", async ({
        page
    }) => {
        await gotoApplication(page, 'bench-01', 'release-moved-on')

        await expect(toggleRow(page, 'inertia_ssr')).toContainText(
            'package.json no longer has a build:ssr script, so it does not start'
        )
        await expect(toggle(page, 'Inertia SSR')).toBeChecked()
        await expect(toggleRow(page, 'reverb')).toHaveCount(0)
    })

    test('an ssr server that did not start says so on the toggle and in the activity', async ({ page }) => {
        await gotoApplication(page, 'hot-01', 'ssr-failed')

        await expect(page.getByTestId('toggle-failed-banner')).toContainText('Inertia SSR did not start.')
        await expect(page.getByTestId('toggle-failed-banner')).toContainText(
            'The SSR server did not start on port 13791.'
        )
        await expect(toggle(page, 'Inertia SSR')).toBeChecked()

        await gotoServerTab(page, 'hot-01', 'Activity')

        await expect(main(page)).toContainText(
            'Inertia SSR did not start on ssr-failed, pages render in the browser until the next deploy'
        )
    })

    test('reverb needs its settings first', async ({ page }) => {
        await gotoApplication(page, 'bench-01', 'reverb-unset')

        await expect(toggleRow(page, 'reverb')).toContainText(
            'Set REVERB_APP_ID, REVERB_APP_KEY and REVERB_APP_SECRET in the environment to turn it on'
        )
        await expect(toggle(page, 'Reverb websockets')).toBeDisabled()
    })
})

test.describe('the PHP version on an app page', () => {
    test('says when composer.lock holds PHP back, and how to move up', async ({ page }, testInfo) => {
        await gotoApplication(page, 'bench-01', own(testInfo, 'held'))

        const banner = page.getByTestId('runtime-held-banner')

        await expect(banner).toContainText('PHP 8.5 is out. composer.lock holds this app on 8.4.')
        await expect(banner).toContainText(
            'Run composer update nette/schema nette/utils, commit composer.lock and deploy.'
        )
    })

    test('can be chosen, and handed back to the repository', async ({ page }, testInfo) => {
        await gotoApplication(page, 'bench-01', own(testInfo, 'held'))
        await page.getByRole('button', { name: 'Change the PHP version' }).click()

        const picker = dialog(page).getByRole('radiogroup', { name: 'PHP version' })

        await expect(picker.getByRole('radio', { name: /^From the repository/ })).toBeChecked()
        await picker.getByRole('radio', { name: /^8\.4\.\d+/ }).click()
        await dialog(page).getByRole('button', { name: 'Save' }).click()
        await expect(toast(page)).toContainText('Saved. The next deploy uses PHP 8.4.')
        await expect(dialog(page)).toHaveCount(0)

        await page.getByRole('button', { name: 'Change the PHP version' }).click()
        await expect(picker.getByRole('radio', { name: /^8\.4\.\d+/ })).toBeChecked()
        await picker.getByRole('radio', { name: /^From the repository/ }).click()
        await dialog(page).getByRole('button', { name: 'Save' }).click()
        await expect(toast(page)).toContainText('Saved. The next deploy uses the version the repository asks for.')
    })
})
