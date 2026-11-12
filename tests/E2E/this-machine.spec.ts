import type { Page } from '@playwright/test'
import { blockingViolations, dialog, expect, gotoServer, gotoServerTab, test, useTheme } from './support/fixtures'

async function rewriteInertia(page: Page, path: string, change: (props: Record<string, any>) => void) {
    await page.route(`**${path}`, async (route) => {
        const response = await route.fetch()

        if (route.request().headers()['x-inertia'] !== undefined) {
            const body = await response.json()
            change(body.props)
            await route.fulfill({ response, json: body })

            return
        }

        const html = await response.text()
        const rewritten = html.replace(
            /(<script data-page="app" type="application\/json">)(.*?)(<\/script>)/s,
            (_match, open: string, json: string, close: string) => {
                const data = JSON.parse(json)
                change(data.props)

                return open + JSON.stringify(data).replace(/</g, '\\u003c').replace(/>/g, '\\u003e') + close
            }
        )

        await route.fulfill({ response, body: rewritten })
    })
}

async function passwordsOn(page: Page, ssh: Record<string, unknown> = {}) {
    await rewriteInertia(page, '/servers/*/hardening', (props) => {
        props.server.this_machine = true
        props.ssh = {
            verified: false,
            owner: true,
            passwords_off: false,
            error: null,
            logins: { password: true, keyboard: false, root: true },
            source: '/etc/ssh/sshd_config.d/50-cloud-init.conf',
            lines: ['PasswordAuthentication no', 'PermitRootLogin prohibit-password'],
            reload: 'ssh',
            checked: 'today 13:00',
            ...ssh
        }
    })
}

test.describe('this machine', () => {
    test.afterEach(({ page }) => page.unrouteAll({ behavior: 'ignoreErrors' }))

    test('Add server offers this machine first, and says which team already has it', async ({ page }) => {
        await page.goto('/servers/create')

        const provider = page.getByLabel('Provider')
        await expect(provider.locator('option').first()).toHaveText('This machine')

        await provider.selectOption('this-machine')

        await expect(page.getByTestId('this-machine-taken')).toHaveText(
            'This machine is already a server in Monoven Ltd.'
        )
        await expect(page.getByLabel('Server name')).not.toHaveValue('')
        await expect(page.getByRole('button', { name: 'Use this machine' })).toHaveCount(0)
    })

    test('where it can be used, this machine is explained in a sentence or two, with one button', async ({ page }) => {
        await rewriteInertia(page, '/servers/create', (props) => {
            if (props.thisMachine) {
                props.thisMachine = { ...props.thisMachine, taken_by: null, installed: true }
            }
        })
        await page.goto('/servers/create')

        await page.getByLabel('Provider').selectOption('this-machine')

        await expect(page.getByTestId('this-machine-explained')).toHaveText(
            'Run your apps on the machine Coresmith runs on. Coresmith gets root here with a key that only works from this machine, then checks and provisions it like any server.'
        )
        await expect(page.getByRole('button', { name: 'Use this machine' })).toBeEnabled()
    })

    test('with no cloud account and this machine free, Add server starts on this machine, named after it, in Production', async ({
        page
    }) => {
        await rewriteInertia(page, '/servers/create', (props) => {
            if (props.thisMachine) {
                props.thisMachine = { ...props.thisMachine, taken_by: null, installed: true }
                props.cloud = { ...props.cloud, selected: 'manual' }
            }
        })
        await page.goto('/servers/create')

        await expect(page.getByLabel('Provider')).toHaveValue('this-machine')
        await expect(page.getByLabel('Server name')).not.toHaveValue('')
        await expect(page.getByLabel('Environment', { exact: true })).toHaveValue('')
        await expect(page.getByRole('button', { name: 'Use this machine' })).toBeEnabled()

        await page.getByLabel('Provider').selectOption('manual')

        await expect(page.getByLabel('Server name')).toHaveValue('')
        await expect(page.getByLabel('IP address')).toBeVisible()
    })

    test('an install whose installer predates this shows the command that brings it up to date', async ({ page }) => {
        await rewriteInertia(page, '/servers/create', (props) => {
            if (props.thisMachine) {
                props.thisMachine = { ...props.thisMachine, taken_by: null, installed: false }
            }
        })
        await page.goto('/servers/create')

        await page.getByLabel('Provider').selectOption('this-machine')

        await expect(page.getByTestId('this-machine-installer')).toContainText('Run it again on this machine')
        await expect(page.getByRole('region', { name: 'Command that runs the installer again' })).toHaveText(
            'curl -fsSL https://get.coresmith.io/install | sudo bash'
        )
        await expect(page.getByRole('button', { name: 'Use this machine' })).toHaveCount(0)
    })

    test("this machine's page explains how Coresmith gets in, shows the helper's refusal and offers only to try again", async ({
        page
    }) => {
        await gotoServer(page, 'coresmith-box')

        const card = page.getByTestId('server-connect')
        await expect(card.getByRole('heading', { name: 'Access to this machine' })).toBeVisible()
        await expect(card.getByTestId('this-machine-access')).toContainText(
            'with a key that only works from the machine itself'
        )
        await expect(card.getByTestId('connect-error')).toContainText("sshd doesn't listen on 127.0.0.1")
        await expect(card.getByRole('button', { name: 'Try again' })).toBeVisible()
        await expect(card.getByRole('button', { name: 'Test connection' })).toHaveCount(0)
        await expect(card.getByText('Run this on the server')).toHaveCount(0)
        await expect(card.getByLabel('Password')).toHaveCount(0)
        await expect(page.getByTestId('audit-report')).toHaveCount(0)
    })

    test('once Coresmith has access to this machine, its page offers the connection test and the check', async ({
        page
    }) => {
        await rewriteInertia(page, '/servers/*', (props) => {
            if (props.server?.this_machine) {
                props.server.provisioning = { ...props.server.provisioning, connected: true, connect_error: null }
            }
        })
        await gotoServer(page, 'coresmith-box')

        const card = page.getByTestId('server-connect')
        await expect(card.getByRole('button', { name: 'Test connection' })).toBeVisible()
        await expect(card.getByRole('button', { name: 'Try again' })).toHaveCount(0)
        await expect(page.getByTestId('audit-report').getByRole('button', { name: 'Check the server' })).toBeVisible()
    })

    test('rebooting this machine asks first, since Coresmith goes down with it', async ({ page }) => {
        await rewriteInertia(page, '/servers/*', (props) => {
            if (props.server?.this_machine) {
                props.server.active = true
            }
        })
        const reboots: string[] = []
        page.on('request', (request) => {
            if (request.method() === 'POST' && request.url().endsWith('/reboot')) {
                reboots.push(request.url())
            }
        })
        await gotoServer(page, 'coresmith-box')

        await page.getByRole('button', { name: 'Reboot server' }).click()

        await expect(dialog(page).getByRole('heading', { name: 'Reboot this machine?' })).toBeVisible()
        await expect(dialog(page)).toContainText('Coresmith runs here too, so it goes down with the machine')

        await dialog(page).getByRole('button', { name: 'Keep it running' }).click()

        await expect(dialog(page)).toHaveCount(0)
        expect(reboots).toEqual([])
    })

    test('detaching this machine says Coresmith takes its key off and keeps running', async ({ page }) => {
        await gotoServer(page, 'coresmith-box')

        await page.getByRole('button', { name: 'Detach' }).click()

        await expect(dialog(page)).toContainText(
            'Coresmith takes its key off this machine and stops managing it. Apps keep running, and so does Coresmith.'
        )

        await dialog(page).getByRole('button', { name: 'Keep it' }).click()
        await expect(dialog(page)).toHaveCount(0)
    })

    test("on this machine, the firewall card says its own SSH rules were kept, and why port 22 can't be denied", async ({
        page
    }) => {
        await rewriteInertia(page, '/servers/*/hardening', (props) => {
            props.server.this_machine = true
            props.firewall = { ...props.firewall, open: [80, 443], kept_ssh: true, ssh_port: 22 }
        })
        await gotoServerTab(page, 'outpost-01', 'Hardening')

        await expect(page.getByTestId('firewall-summary')).toHaveText(
            "This machine's firewall was already on. Provisioning opened 80, 443 and kept its SSH rules. Changes Coresmith applied are listed here."
        )
        await expect(page.getByTestId('firewall-ssh-note')).toHaveText(
            "Port 22 is how people sign in to this machine, so it can't be denied here."
        )
    })

    test('on this machine the Hardening tab says SSH is left as you set it up, and whether it takes passwords', async ({
        page
    }) => {
        await rewriteInertia(page, '/servers/*/hardening', (props) => {
            props.server.this_machine = true
            props.ssh = { verified: false, owner: true, passwords_off: true, error: null }
        })
        await gotoServerTab(page, 'outpost-01', 'Hardening')

        await expect(page.getByTestId('ssh-access-summary')).toHaveText(
            "Coresmith signs in as root with a key that only works from this machine itself. Password login is off in this machine's own SSH settings, which Coresmith leaves to you."
        )
        await expect(page.getByTestId('ssh-hardening-unchecked')).toHaveCount(0)
    })

    test('when password login is on, this machine shows the exact steps to turn it off', async ({ page }) => {
        await passwordsOn(page)
        await gotoServerTab(page, 'outpost-01', 'Hardening')

        const warning = page.getByTestId('ssh-passwords-warning')
        await expect(warning.getByText('Password login is on.', { exact: true })).toBeVisible()
        await expect(warning).toContainText(
            "Anyone can try passwords against this machine's SSH. /etc/ssh/sshd_config.d/50-cloud-init.conf turns it on."
        )
        await expect(warning).toContainText('Check that you can sign in with your SSH key, and keep that session open.')
        await expect(warning.getByTestId('ssh-passwords-commands')).toHaveText(
            "printf '%s\\n' 'PasswordAuthentication no' 'PermitRootLogin prohibit-password' | sudo tee /etc/ssh/sshd_config.d/01-no-passwords.conf\nsudo sshd -t && sudo systemctl reload ssh"
        )
        await expect(warning).toContainText('Then check again.')
    })

    test('only root by password on Rocky reloads sshd and writes one line', async ({ page }) => {
        await passwordsOn(page, {
            logins: { password: false, keyboard: false, root: true },
            source: '/etc/ssh/sshd_config',
            lines: ['PermitRootLogin prohibit-password'],
            reload: 'sshd'
        })
        await gotoServerTab(page, 'outpost-01', 'Hardening')

        const warning = page.getByTestId('ssh-passwords-warning')
        await expect(warning.getByText('Root can sign in with a password.', { exact: true })).toBeVisible()
        await expect(warning.getByTestId('ssh-passwords-commands')).toHaveText(
            "printf '%s\\n' 'PermitRootLogin prohibit-password' | sudo tee /etc/ssh/sshd_config.d/01-no-passwords.conf\nsudo sshd -t && sudo systemctl reload sshd"
        )
    })

    test('a login sshd turns on by itself says so', async ({ page }) => {
        await passwordsOn(page, {
            logins: { password: false, keyboard: true, root: false },
            source: null,
            lines: ['KbdInteractiveAuthentication no']
        })
        await gotoServerTab(page, 'outpost-01', 'Hardening')

        await expect(page.getByTestId('ssh-passwords-warning')).toContainText(
            "Anyone can try passwords against this machine's SSH. sshd turns it on by default."
        )
    })

    test('Check again asks Coresmith to read the settings', async ({ page }) => {
        await passwordsOn(page)
        await page.route('**/ssh-check', (route) =>
            route.fulfill({ status: 409, headers: { 'x-inertia-location': page.url() } })
        )
        await gotoServerTab(page, 'outpost-01', 'Hardening')

        const request = page.waitForRequest((sent) => sent.url().endsWith('/ssh-check') && sent.method() === 'POST')
        await page.getByTestId('ssh-passwords-warning').getByRole('button', { name: 'Check again' }).click()
        await request
    })

    test('no warning once password login is off', async ({ page }) => {
        await rewriteInertia(page, '/servers/*/hardening', (props) => {
            props.server.this_machine = true
            props.ssh = {
                ...props.ssh,
                verified: false,
                owner: true,
                passwords_off: true,
                error: null,
                logins: null,
                source: null,
                lines: []
            }
        })
        await gotoServerTab(page, 'outpost-01', 'Hardening')

        await expect(page.getByTestId('ssh-access-summary')).toBeVisible()
        await expect(page.getByTestId('ssh-passwords-warning')).toHaveCount(0)
    })

    for (const theme of ['light', 'dark'] as const) {
        test(`the password login warning has no serious or critical violations in ${theme}`, async ({ page }) => {
            await useTheme(page, theme)
            await passwordsOn(page)
            await gotoServerTab(page, 'outpost-01', 'Hardening')
            await expect(page.getByTestId('ssh-passwords-warning')).toBeVisible()

            expect(await blockingViolations(page), `password warning in ${theme}`).toEqual([])
        })
    }
})
