import {
    applicationRow,
    dialog,
    expect,
    gotoServer,
    gotoServerTab,
    isMobile,
    own,
    tabComesIntoViewAsStripNarrows,
    tabs,
    tabStaysInViewAsFontLoads,
    test,
    toast
} from './support/fixtures'

test.describe('a server', () => {
    test('the header names it, says how it is doing and what it is', async ({ page }) => {
        await gotoServer(page, 'forge-01')

        await expect(page.getByTestId('back-link')).toHaveText('← Fleet')
        await expect(page.getByRole('main')).toContainText('Healthy')
        await expect(page.getByRole('main')).toContainText('Production · fra1 · 8 GB · Ubuntu 24.04 · 203.0.113.14')
    })

    test('the last day of readings is drawn for cpu, memory and disk', async ({ page }) => {
        await gotoServer(page, 'forge-01')

        for (const label of ['CPU · 24h', 'Memory · 24h', 'Disk · 24h']) {
            const metric = page.getByText(label, { exact: true }).locator('xpath=../..')

            await expect(metric).toContainText(/\d+%/)
            await expect(metric.locator('svg path')).toHaveCount(1)
        }
    })

    test('the sections are tabs that mark the current one', async ({ page }) => {
        await gotoServer(page, 'forge-01')

        const sections = tabs(page, 'Server sections')

        await expect(sections.getByRole('link', { name: 'Apps', exact: true })).toHaveAttribute('aria-current', 'page')

        for (const [label, url] of [
            ['Databases', /\/databases$/],
            ['Hardening', /\/hardening$/],
            ['Activity', /\/activity$/],
            ['Apps', /\/servers\/[A-Za-z0-9]+$/]
        ] as const) {
            await sections.getByRole('link', { name: label, exact: true }).click()
            await page.waitForURL(url)
            await expect(tabs(page, 'Server sections').getByRole('link', { name: label, exact: true })).toHaveAttribute(
                'aria-current',
                'page'
            )
        }
    })

    test('the last tab stays in view when the web font widens the tabs', async ({ page }) => {
        await tabStaysInViewAsFontLoads(page, '/dashboard', 'Server sections', 'Activity', async () => {
            await page.getByTestId('server-card').getByRole('link', { name: 'forge-01', exact: true }).click()
            await tabs(page, 'Server sections').getByRole('link', { name: 'Activity', exact: true }).click()
        })
    })

    test('the last tab comes into view when the strip narrows', async ({ page }) => {
        await gotoServerTab(page, 'forge-01', 'Activity')
        await tabComesIntoViewAsStripNarrows(page, page.url(), 'Server sections', 'Activity')
    })

    test('the apps tab lists each app with how it runs', async ({ page }, testInfo) => {
        await gotoServer(page, 'forge-01')

        for (const name of ['atlas', 'ledger', 'relay', 'storefront']) {
            await expect(applicationRow(page, name)).toBeVisible()
        }

        if (!isMobile(testInfo)) {
            await expect(applicationRow(page, 'atlas')).toContainText('coresmith-app-atlas')
            await expect(applicationRow(page, 'atlas')).toContainText('8000–8009')
        }

        await applicationRow(page, 'ledger').click()
        await expect(page.getByRole('heading', { level: 1, name: 'ledger' })).toBeVisible()
    })

    test('the apps table shows each app’s real runtime version', async ({ page }, testInfo) => {
        test.skip(
            isMobile(testInfo),
            'The Runtime column is hidden below 640px, matching the prototype’s mobile apps table'
        )

        await gotoServer(page, 'forge-01')

        await expect(applicationRow(page, 'relay')).toContainText('Deno 2.9')
        await expect(applicationRow(page, 'storefront')).toContainText('Bun 1.4')
        await expect(applicationRow(page, 'atlas')).toContainText('PHP 8.5')
    })

    test('a server with a nearly full disk says so and offers to prune', async ({ page }) => {
        await gotoServer(page, 'outpost-01')

        const warning = page.getByTestId('disk-warning')

        await expect(warning).toContainText('Disk is at 84%.')
        await warning.getByRole('button', { name: 'Prune old releases' }).click()

        await expect(toast(page)).toContainText('Pruning old releases.')
    })

    test('a healthy server shows no disk warning', async ({ page }) => {
        await gotoServer(page, 'forge-01')

        await expect(page.getByTestId('disk-warning')).toHaveCount(0)
    })

    test('a reboot is scheduled from the header', async ({ page }) => {
        await gotoServer(page, 'anvil-01')

        await page.getByRole('button', { name: 'Reboot server' }).click()

        await expect(toast(page)).toContainText('Reboot scheduled.')
    })

    test('a reboot is refused while a deploy runs on the server', async ({ page }) => {
        await gotoServer(page, 'forge-02')

        await page.getByRole('button', { name: 'Reboot server' }).click()

        await expect(toast(page)).toContainText('A deploy is running on forge-02.')
    })

    test('a server can be renamed', async ({ page }, testInfo) => {
        const name = own(testInfo, 'rename')
        const renamed = `${name}-renamed`

        await gotoServer(page, name)

        await page.getByRole('button', { name: 'Rename' }).click()
        await expect(dialog(page)).toContainText('Rename server')
        await dialog(page).getByRole('textbox', { name: 'Server name' }).fill(renamed)
        await dialog(page).getByRole('button', { name: 'Save' }).click()

        await expect(page.getByRole('heading', { level: 1, name: renamed })).toBeVisible()
        await expect(toast(page)).toContainText('Saved.')
        await expect(dialog(page)).toHaveCount(0)
    })

    test('detaching asks first, then removes the server from the panel', async ({ page }, testInfo) => {
        const name = own(testInfo, 'detach')

        await gotoServer(page, name)

        await page.getByRole('button', { name: 'Detach' }).click()
        await expect(dialog(page)).toContainText(`Detach ${name}?`)
        await expect(dialog(page)).toContainText('The VM itself, and everything on it, is untouched.')
        await dialog(page).getByRole('button', { name: 'Detach server' }).click()

        await page.waitForURL(/\/dashboard$/)
        await expect(toast(page)).toContainText('Detached.')
        await expect(page.getByTestId('server-card').getByRole('link', { name, exact: true })).toHaveCount(0)
    })

    test('detaching can be called off', async ({ page }) => {
        await gotoServer(page, 'anvil-01')

        await page.getByRole('button', { name: 'Detach' }).click()
        await dialog(page).getByRole('button', { name: 'Keep it' }).click()

        await expect(dialog(page)).toHaveCount(0)
        await expect(page.getByRole('heading', { level: 1, name: 'anvil-01' })).toBeVisible()
    })

    test('the private half of the server key never reaches the page', async ({ page }) => {
        await gotoServerTab(page, 'forge-01', 'Hardening')

        const html = await page.content()

        expect(html).not.toContain('BEGIN OPENSSH PRIVATE KEY')
        expect(html).not.toContain('ssh_private_key')
    })
})

test.describe('hardening', () => {
    test('the isolation layers and the server hardening are listed', async ({ page }) => {
        await gotoServerTab(page, 'forge-01', 'Hardening')

        const isolation = page.getByRole('heading', { name: 'Nine-layer app isolation' }).locator('..')

        await expect(isolation.getByRole('listitem')).toHaveCount(9)
        await expect(page.getByRole('heading', { name: 'Server hardening' })).toBeVisible()
        await expect(page.getByRole('switch', { name: 'Audit logging' })).toBeVisible()
    })

    test('ssh access shows how Coresmith signs in, never a password', async ({ page }) => {
        await gotoServerTab(page, 'forge-01', 'Hardening')

        const access = page.getByRole('heading', { name: 'SSH access' }).locator('..')

        await expect(access).toContainText('root@203.0.113.14:22')
        await expect(access).toContainText('ssh-ed25519 ')
        await expect(access).toContainText('Password login is off.')
    })

    test('the firewall lists the changes Coresmith applied', async ({ page }) => {
        await gotoServerTab(page, 'forge-01', 'Hardening')

        const firewall = page.getByTestId('firewall')

        await expect(firewall).toContainText('Default-deny.')
        await expect(firewall).toContainText('allow 9090 from 203.0.113.50')
    })

    test('a firewall rule is queued for the server', async ({ page }) => {
        await gotoServerTab(page, 'anvil-01', 'Hardening')

        const firewall = page.getByTestId('firewall')

        await firewall.getByRole('spinbutton', { name: 'Port' }).fill('9443')
        await firewall.getByRole('textbox', { name: 'From IP' }).fill('198.51.100.7')
        await firewall.getByRole('button', { name: 'Apply' }).click()

        await expect(toast(page)).toContainText('Queued.')
    })

    test('the ssh port cannot be denied', async ({ page }) => {
        await gotoServerTab(page, 'anvil-01', 'Hardening')

        const firewall = page.getByTestId('firewall')

        await firewall.getByRole('combobox', { name: 'Rule' }).selectOption('deny')
        await firewall.getByRole('spinbutton', { name: 'Port' }).fill('22')
        await firewall.getByRole('button', { name: 'Apply' }).click()

        await expect(toast(page)).toContainText('That is the SSH port Coresmith uses to reach this server.')
    })

    test('a malformed source address is refused next to the field', async ({ page }) => {
        await gotoServerTab(page, 'anvil-01', 'Hardening')

        const firewall = page.getByTestId('firewall')

        await firewall.getByRole('spinbutton', { name: 'Port' }).fill('8443')
        await firewall.getByRole('textbox', { name: 'From IP' }).fill('not-an-address')
        await firewall.getByRole('button', { name: 'Apply' }).click()

        await expect(firewall.getByRole('textbox', { name: 'From IP' })).toHaveAttribute('aria-invalid', 'true')
    })
})

test.describe('activity', () => {
    test('what happened on the server is listed newest first', async ({ page }) => {
        await gotoServerTab(page, 'forge-01', 'Activity')

        const activity = page.getByTestId('server-activity')

        await expect(activity).toContainText('9090')
        await expect(activity).toContainText('atlas')
        await expect(activity).toContainText('atlas now clones monoventech/atlas through GitHub on monoventech')
    })
})

test.describe('a server that is not connected yet', () => {
    test('it shows the key to install and the phases to come', async ({ page }) => {
        await gotoServer(page, 'empty-server')

        await expect(page.getByRole('heading', { name: 'Connect empty-server' })).toBeVisible()
        await expect(page.getByTestId('public-key')).toContainText(/^ssh-ed25519 /)
        await expect(page.getByTestId('provisioning-phases')).toContainText('What happens next')
        await expect(page.getByRole('main')).not.toContainText('CPU · 24h')
    })

    test('the connection can be tested', async ({ page }) => {
        await gotoServer(page, 'empty-server')

        await page.getByRole('button', { name: 'Test connection' }).click()

        await expect(toast(page)).toContainText('Testing the connection.')
    })
})

test.describe('provisioning a server', () => {
    test('the fleet offers it', async ({ page }, testInfo) => {
        test.skip(isMobile(testInfo), 'The provision action is hidden below 640px, matching the phone Fleet')

        await page.goto('/dashboard')
        await page.getByRole('link', { name: 'Provision a server' }).click()

        await page.waitForURL(/\/servers\/create$/)
        await expect(page.getByRole('heading', { level: 1, name: 'Provision a server' })).toBeVisible()
        await expect(page.getByRole('main')).toContainText('What happens next')
    })

    test('a new server is added and asks for its key to be installed', async ({ page }) => {
        const name = `e2e-${Date.now().toString(36)}`

        await page.goto('/servers/create')
        await page.getByLabel('Provider').selectOption('manual')
        await page.getByLabel('Server name').fill(name)
        await page.getByLabel('IP address').fill('198.51.100.7')
        await page.getByLabel('Region').fill('lon1')
        await page.getByRole('button', { name: 'Add server' }).click()

        await expect(page.getByRole('heading', { level: 1, name })).toBeVisible()
        await expect(toast(page)).toContainText('Server added.')
        await expect(page.getByTestId('public-key')).toContainText(/^ssh-ed25519 /)
    })

    test("the form starts on the team's first provider account", async ({ page }) => {
        await page.goto('/servers/create')

        await expect(page.getByLabel('Provider')).toHaveValue(/^account:/)
    })

    test('an address that is not an ip is refused next to the field', async ({ page }) => {
        await page.goto('/servers/create')
        await page.getByLabel('Provider').selectOption('manual')
        await page.getByLabel('Server name').fill('bad-address')
        await page.getByLabel('IP address').fill('not-an-ip')
        await page.getByRole('button', { name: 'Add server' }).click()

        await expect(page.getByLabel('IP address')).toHaveAttribute('aria-invalid', 'true')
        await expect(page.getByText("That doesn't look like an IP address. Try 203.0.113.40.")).toBeVisible()
    })
})
