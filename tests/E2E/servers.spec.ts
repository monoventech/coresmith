import {
    applicationRow,
    blockingViolations,
    dialog,
    expect,
    expectWayBack,
    gotoServer,
    gotoServerTab,
    isMobile,
    own,
    pageData,
    refuseClipboard,
    tabComesIntoViewAsStripNarrows,
    tabs,
    tabStaysInViewAsTabsWiden,
    test,
    toast,
    useTheme,
    withProps
} from './support/fixtures'

test.describe('a server', () => {
    test('the header names it, says how it is doing and what it is', async ({ page }) => {
        await gotoServer(page, 'forge-01')

        await expectWayBack(page, 'Fleet')
        await expect(page.getByRole('main')).toContainText('Healthy')
        await expect(page.getByRole('main')).toContainText(
            'Production · fra1 · 4 cores · 8 GB RAM · 160 GB disk · Ubuntu 24.04.3 LTS · 203.0.113.14'
        )
    })

    test('each reading card says what its percentage is of, in plain amounts', async ({ page }) => {
        await gotoServer(page, 'forge-01')

        const detail = (label: string) =>
            page.getByText(label, { exact: true }).locator('xpath=../..').getByTestId('metric-detail')

        await expect(detail('CPU · 24h')).toHaveText('AMD EPYC-Milan Processor · 4 cores · load 1.36')
        await expect(detail('Memory · 24h')).toHaveText(/^\d+(\.\d)?\sGB of 8\sGB used$/)
        await expect(detail('Disk · 24h')).toHaveText('67 GB of 160 GB used · 85 GB free')
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

    test('the last tab stays in view when the tabs widen', async ({ page }) => {
        await gotoServerTab(page, 'forge-01', 'Activity')
        await tabStaysInViewAsTabsWiden(page, page.url(), 'Server sections', 'Activity')
    })

    test('the last tab comes into view when the strip narrows', async ({ page }) => {
        await gotoServerTab(page, 'forge-01', 'Activity')
        await tabComesIntoViewAsStripNarrows(page, page.url(), 'Server sections', 'Activity')
    })

    test('the apps tab lists each app with its domain and last deploy, and leaves the machinery to its own page', async ({
        page
    }, testInfo) => {
        await gotoServer(page, 'forge-01')

        for (const name of ['atlas', 'ledger', 'relay', 'storefront']) {
            await expect(applicationRow(page, name)).toBeVisible()
        }

        if (!isMobile(testInfo)) {
            await expect(applicationRow(page, 'atlas')).toContainText('atlas.monoven.io')
            await expect(applicationRow(page, 'atlas')).not.toContainText('coresmith-app-atlas')
            await expect(page.getByTestId('applications-table')).toContainText('Last deploy')
        }

        await expect(page.getByRole('link', { name: 'What Coresmith runs here' })).toBeVisible()

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

    test('renaming sets and clears the environment the fleet shows', async ({ page }, testInfo) => {
        const name = own(testInfo, 'label')

        await gotoServer(page, name)
        await expect(page.getByText(/^ams3 · /)).toBeVisible()

        await page.getByRole('button', { name: 'Rename' }).click()
        await expect(dialog(page).getByLabel('Environment')).toHaveValue('')
        await dialog(page).getByLabel('Environment').selectOption('Staging')
        await dialog(page).getByRole('button', { name: 'Save' }).click()

        await expect(toast(page)).toContainText('Saved.')
        await expect(page.getByText(/^Staging · ams3 · /)).toBeVisible()

        await page.getByRole('button', { name: 'Rename' }).click()
        await expect(dialog(page).getByLabel('Environment')).toHaveValue('Staging')
        await dialog(page).getByLabel('Environment').selectOption({ label: 'No environment' })
        await dialog(page).getByRole('button', { name: 'Save' }).click()

        await expect(dialog(page)).toHaveCount(0)
        await expect(page.getByText(/^ams3 · /)).toBeVisible()
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
        await expect(dialog(page)).toContainText('Coresmith takes its key off this server and stops managing it.')
        await dialog(page).getByRole('button', { name: 'Detach server' }).click()

        await page.waitForURL(/\/dashboard$/)
        await expect(toast(page)).toContainText(
            `Detached. Coresmith couldn't take its key off ${name}, so remove the line ending in coresmith from /root/.ssh/authorized_keys there.`
        )
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

        const html = await pageData(page)

        expect(html).toContain('forge-01')
        expect(html).not.toContain('BEGIN OPENSSH PRIVATE KEY')
        expect(html).not.toContain('ssh_private_key')
    })
})

type Protection = { key: string; label: string; state: string; detail: string; fix: string | null }

function protectionRow(props: Record<string, unknown>, key: string, change: Partial<Protection>) {
    const protections = props.protections as { checked: string | null; rows: Protection[] }

    protections.rows = protections.rows.map((row) => (row.key === key ? { ...row, ...change } : row))
}

test.describe('hardening', () => {
    test('each protection shows its real state on the server and when Coresmith last checked', async ({ page }) => {
        await gotoServerTab(page, 'forge-01', 'Hardening')

        const card = page.getByTestId('protections')

        await expect(card.getByRole('heading', { name: 'Server hardening' })).toBeVisible()
        await expect(page.getByTestId('protections-checked')).toHaveText(
            /^Checked (today \d{2}:\d{2}|[A-Z][a-z]{2} \d{1,2})$/
        )

        for (const key of ['ssh', 'fail2ban', 'kernel', 'patches', 'controls', 'unknown_hosts']) {
            await expect(page.getByTestId(`protection-${key}`)).toHaveAttribute('data-state', 'on')
        }

        await expect(page.getByTestId('protection-fail2ban')).toContainText(
            'An address that fails to sign in 5 times in 10 minutes is blocked for an hour.'
        )
        await expect(card.getByRole('button')).toHaveCount(0)
        await expect(card).not.toContainText(/Caddy|systemd|fail2ban|sysctl|admin API/)
        await expect(page.getByRole('switch', { name: 'Audit logging' })).toBeVisible()
    })

    test('a server Coresmith has not checked yet says when it will', async ({ page }) => {
        await gotoServerTab(page, 'bench-01', 'Hardening')

        await expect(page.getByTestId('protections-checked')).toHaveText('Not checked yet')
        await expect(page.getByTestId('protection-fail2ban')).toHaveAttribute('data-state', 'pending')
        await expect(page.getByTestId('protection-fail2ban')).toContainText('Coresmith checks this within the hour.')
        await expect(page.getByTestId('protection-ssh')).toContainText(
            'Coresmith turns off password login within the hour.'
        )
    })

    test('a protection that is off says so and asks Coresmith to turn it back on', async ({ page }) => {
        await withProps(page, /\/hardening$/, (props) => {
            protectionRow(props, 'fail2ban', {
                state: 'off',
                detail: "It isn't running on this server.",
                fix: 'Turn it back on'
            })
        })
        await page.route('**/protections/fail2ban', (route) =>
            route.fulfill({ status: 409, headers: { 'x-inertia-location': page.url() } })
        )
        await gotoServerTab(page, 'forge-01', 'Hardening')

        const row = page.getByTestId('protection-fail2ban')

        await expect(row).toHaveAttribute('data-state', 'off')
        await expect(row).toContainText("It isn't running on this server.")

        const request = page.waitForRequest(
            (sent) => sent.url().endsWith('/protections/fail2ban') && sent.method() === 'POST'
        )
        await row.getByRole('button', { name: 'Turn it back on' }).click()
        await request
    })

    test('a fix that is running says so, with nothing to press', async ({ page }) => {
        await withProps(page, /\/hardening$/, (props) => {
            protectionRow(props, 'patches', { state: 'busy', detail: 'Turning them back on…', fix: null })
        })
        await gotoServerTab(page, 'forge-01', 'Hardening')

        const row = page.getByTestId('protection-patches')

        await expect(row).toHaveAttribute('data-state', 'busy')
        await expect(row).toContainText('Turning them back on…')
        await expect(row).toContainText('Working')
        await expect(row.getByRole('button')).toHaveCount(0)
    })

    test('someone who cannot change the server sees the state but no fix', async ({ page }) => {
        await withProps(page, /\/hardening$/, (props) => {
            ;(props.server as { can: { update: boolean } }).can.update = false
            protectionRow(props, 'kernel', {
                state: 'off',
                detail: '1 of 15 settings changed since Coresmith set them.',
                fix: 'Set them again'
            })
        })
        await gotoServerTab(page, 'forge-01', 'Hardening')

        await expect(page.getByTestId('protection-kernel')).toContainText('Off')
        await expect(page.getByTestId('protection-kernel').getByRole('button')).toHaveCount(0)
    })

    test('the tab leads to what Coresmith runs on the server, for whoever signs in over SSH', async ({ page }) => {
        await gotoServerTab(page, 'forge-01', 'Hardening')
        await page.getByTestId('under-the-hood-link').click()

        await expect(page.getByRole('heading', { level: 1, name: 'What Coresmith runs here' })).toBeVisible()
        await expect(page.getByTestId('machinery-apps')).toContainText('coresmith-app-atlas')
        await expect(page.getByTestId('machinery-apps')).toContainText('8000–8009')
        await expect(page.getByTestId('machinery-isolation').getByRole('listitem')).toHaveCount(9)
        await expect(page.getByTestId('machinery-files')).toContainText('/etc/fail2ban/jail.d/coresmith-ssh.conf')
    })

    test.describe('accessibility', () => {
        test.use({ reducedMotion: 'reduce' })

        for (const theme of ['light', 'dark'] as const) {
            test(`a protection that is off has no serious or critical violations in ${theme}`, async ({ page }) => {
                await useTheme(page, theme)
                await withProps(page, /\/hardening$/, (props) => {
                    protectionRow(props, 'controls', {
                        state: 'off',
                        detail: "Apps on this server can still reach the controls that route traffic. Coresmith's last try to close them failed: curl: (7) Failed to connect to localhost port 2019",
                        fix: 'Try again now'
                    })
                    protectionRow(props, 'patches', { state: 'busy', detail: 'Turning them back on…', fix: null })
                })
                await gotoServerTab(page, 'forge-01', 'Hardening')
                await expect(page.getByTestId('protection-controls')).toHaveAttribute('data-state', 'off')

                expect(await blockingViolations(page), `protections in ${theme}`).toEqual([])
            })

            test(`what Coresmith runs here has no serious or critical violations in ${theme}`, async ({ page }) => {
                await useTheme(page, theme)
                await gotoServerTab(page, 'forge-01', 'Hardening')
                await page.getByTestId('under-the-hood-link').click()
                await expect(page.getByTestId('machinery-files')).toBeVisible()

                expect(await blockingViolations(page), `what Coresmith runs in ${theme}`).toEqual([])
            })
        }
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

        await expect(firewall).toContainText('Closed by default.')
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
    test('it offers a password login, the command that adds its key, and the phases to come', async ({ page }) => {
        await gotoServer(page, 'empty-server')

        const card = page.getByTestId('server-connect')

        await expect(card.getByRole('heading', { name: 'Connect empty-server' })).toBeVisible()
        await expect(card.getByLabel('SSH user')).toHaveValue('root')
        await expect(card.getByLabel('Password')).toHaveAttribute('autocomplete', 'new-password')
        await expect(page.getByTestId('connect-command')).toHaveText(
            /^sudo sh -c 'k="ssh-ed25519 [A-Za-z0-9+/]+ [^"]+"; mkdir -p ~root\/\.ssh && /
        )
        await expect(card.getByRole('alert')).toHaveCount(0)
        await expect(page.getByTestId('provisioning-phases')).toContainText('What happens next')
        await expect(page.getByRole('main')).not.toContainText('CPU · 24h')
    })

    test('a connection test that cannot reach the server says why on the card', async ({ page }, testInfo) => {
        const name = own(testInfo, 'connect')

        await gotoServer(page, name)
        await page.getByRole('button', { name: 'Test connection' }).click()

        await expect(toast(page)).toContainText(`Coresmith couldn't connect to ${name}.`)
        await expect(page.getByTestId('connect-error')).toHaveText(
            'The connection to port 1 was refused or timed out. Check the server is running and its firewall allows SSH from Coresmith.'
        )
    })

    test('a password login that cannot reach the server says why, and the password leaves the form', async ({
        page
    }, testInfo) => {
        const name = own(testInfo, 'connect')
        const password = `pw-${testInfo.project.name}-never-shown`

        await gotoServer(page, name)

        const card = page.getByTestId('server-connect')

        await card.getByLabel('SSH user').fill('maya')
        await card.getByLabel('Password').fill(password)
        await card.getByRole('button', { name: 'Connect', exact: true }).click()

        await expect(toast(page)).toContainText(`Coresmith couldn't connect to ${name}.`)
        await expect(page.getByTestId('connect-error')).toContainText(
            'The connection to port 1 was refused or timed out.'
        )
        await expect(card.getByLabel('Password')).toHaveValue('')
        expect(await page.content()).not.toContain(password)
    })

    test('a blocked clipboard says to copy the command by hand', async ({ page }) => {
        await refuseClipboard(page)
        await gotoServer(page, 'empty-server')

        await page.getByRole('button', { name: 'Copy the command' }).click()

        await expect(page.getByTestId('server-connect').getByRole('status')).toHaveText(
            'This browser blocked copying. Select the command above and copy it yourself.'
        )
    })

    test('a server that connected and was checked says so instead of asking for its key again', async ({ page }) => {
        await gotoServer(page, 'audit-server')

        const card = page.getByTestId('server-connect')

        await expect(card.getByRole('heading', { name: 'Connection' })).toBeVisible()
        await expect(card).toContainText('Connected. Coresmith signs in as root with its own key.')
        await expect(card.getByLabel('Password')).toHaveCount(0)
        await expect(page.getByTestId('connect-command')).toHaveCount(0)
        await expect(card.getByRole('button', { name: 'Test connection' })).toBeVisible()
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

    test('a new server is added and offers both ways to connect it', async ({ page }) => {
        const name = `e2e-${Date.now().toString(36)}`

        await page.goto('/servers/create')
        await page.getByLabel('Provider').selectOption('manual')
        await page.getByLabel('Server name').fill(name)
        await page.getByLabel('IP address').fill('198.51.100.7')
        await page.getByLabel('Region').fill('lon1')
        await page.getByRole('button', { name: 'Add server' }).click()

        await expect(page.getByRole('heading', { level: 1, name })).toBeVisible()
        await expect(toast(page)).toContainText('Server added. Connect it with a password or one command.')
        await expect(page.getByTestId('server-connect').getByLabel('Password')).toBeVisible()
        await expect(page.getByTestId('connect-command')).toContainText(`coresmith@${name}`)
    })

    test('a server added with a password it cannot use is still added, and says why', async ({ page }) => {
        const name = `e2e-pw-${Date.now().toString(36)}`

        await page.goto('/servers/create')
        await page.getByLabel('Provider').selectOption('manual')
        await page.getByLabel('Server name').fill(name)
        await page.getByLabel('IP address').fill('0.0.0.1')
        await page.getByLabel('SSH port').fill('1')
        await page.getByLabel('SSH user').fill('maya')
        await page.getByLabel('Password, optional').fill('not-a-real-password')
        await page.getByRole('button', { name: 'Add server' }).click()

        await expect(page.getByRole('heading', { level: 1, name })).toBeVisible()
        await expect(toast(page)).toContainText("Server added, but Coresmith couldn't connect to it.")
        await expect(page.getByTestId('connect-error')).toContainText(
            'The connection to port 1 was refused or timed out.'
        )
        await expect(page.getByTestId('server-connect').getByLabel('SSH user')).toHaveValue('maya')
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

    test("the machine Coresmith runs on isn't taken as a VM, and the form says it's already a server", async ({
        page
    }) => {
        await page.goto('/servers/create')
        await page.getByLabel('Provider').selectOption('manual')
        await page.getByLabel('Server name').fill('own-machine')
        await page.getByLabel('IP address').fill('127.0.0.1')
        await page.getByRole('button', { name: 'Add server' }).click()

        await expect(page.getByLabel('IP address')).toHaveAttribute('aria-invalid', 'true')
        await expect(
            page.getByText("That's the machine Coresmith runs on, and it's already a server in Monoven Ltd.")
        ).toBeVisible()
    })
})
