import type { Page } from '@playwright/test'
import { expect } from '@playwright/test'
import type { ChildProcess } from 'node:child_process'
import { spawn } from 'node:child_process'
import { existsSync, mkdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { asMember, blockingViolations, dialog, gotoSettings, isMobile, test, toast, useTheme } from './support/fixtures'

const STATE = resolve('storage/app/e2e-updates')

const RUN = '01jbze2e0000000000000000aa'

let holder: ChildProcess | null = null

function card(page: Page) {
    return page.getByTestId('panel-updates-card')
}

function writeStatus(state: string, step: string, extra: Record<string, unknown> = {}, log: string[] = []) {
    const now = new Date().toISOString()
    const status = {
        schema: 1,
        id: RUN,
        state,
        trigger: 'panel',
        requested_by: null,
        from: { version: '0.1.0', release: '0.1.0-0000000' },
        to: { version: '9.9.9', release: '9.9.9-a1b2c3d' },
        step,
        failed_step: null,
        message: null,
        signature: 'unchecked',
        markers: {
            built: '9.9.9-a1b2c3d',
            maintenance: false,
            backup: null,
            migrating: false,
            switched: false,
            healthy: false
        },
        started_at: now,
        updated_at: now,
        finished_at: state === 'running' ? null : now,
        ...extra
    }

    mkdirSync(`${STATE}/runs`, { recursive: true })
    writeFileSync(`${STATE}/status.json`, JSON.stringify(status))
    writeFileSync(`${STATE}/runs/${RUN}.json`, JSON.stringify(status))

    if (log.length > 0) {
        writeFileSync(`${STATE}/runs/${RUN}.log`, `${log.join('\n')}\n`)
    }
}

function writeRequest(file: 'request.json' | 'request.taken.json', secondsAgo: number) {
    const requestedAt = new Date(Date.now() - secondsAgo * 1000).toISOString()

    mkdirSync(STATE, { recursive: true })
    writeFileSync(
        `${STATE}/${file}`,
        JSON.stringify({ schema: 1, to: '9.9.9', trigger: 'panel', requested_by: null, requested_at: requestedAt })
    )
}

async function running(page: Page) {
    await holdLock()
    writeStatus('running', 'dependencies', {}, ['Fetching the release', 'Installing PHP dependencies'])
    await gotoSettings(page, 'General')
}

async function height(page: Page, name: string, within: 'card' | 'dialog') {
    const scope = within === 'card' ? card(page) : dialog(page)

    return scope.getByRole('button', { name, exact: true }).evaluate((button) => (button as HTMLElement).offsetHeight)
}

async function holdLock() {
    mkdirSync(STATE, { recursive: true })
    holder = spawn('php', [
        '-r',
        '$f = fopen($argv[1], "c"); flock($f, LOCK_EX); echo "locked\\n"; sleep(600);',
        `${STATE}/update.lock`
    ])

    await new Promise<void>((done, fail) => {
        holder?.stdout?.on('data', (chunk: Buffer) => chunk.toString().includes('locked') && done())
        holder?.on('error', fail)
    })
}

async function available(page: Page) {
    mkdirSync(STATE, { recursive: true })
    writeFileSync(
        `${STATE}/check.json`,
        JSON.stringify({
            checked_at: new Date().toISOString(),
            url: 'http://127.0.0.1:8125/v1/version',
            feed: {
                schema: 1,
                latest: '9.9.9',
                minimum: null,
                withdrawn: [],
                changelog_url: 'https://github.com/monoven/coresmith/releases/tag/v9.9.9',
                summary: 'Faster deploys on large repositories.',
                released_at: null,
                channel: 'stable'
            },
            error: null,
            failed_at: null
        })
    )
    await gotoSettings(page, 'General')
    await expect(card(page)).toContainText('v9.9.9 is available')
}

async function rolledBack(page: Page) {
    writeStatus(
        'rolled_back',
        'rollback',
        {
            failed_step: 'migrate',
            message:
                'Running migrations failed: UNIQUE constraint failed: users.email. Coresmith rolled back to v0.1.0, and the panel is running as before.'
        },
        ['Running migrations', 'SQLSTATE[23000]: UNIQUE constraint failed: users.email', 'Rolling back']
    )
    await gotoSettings(page, 'General')
}

test.afterEach(() => {
    holder?.kill('SIGKILL')
    holder = null
    rmSync(STATE, { recursive: true, force: true })
})

test.describe('panel updates', () => {
    test('the card shows the version and checks the feed', async ({ page }) => {
        await gotoSettings(page, 'General')

        await expect(card(page)).toContainText('Coresmith v')
        await expect(card(page)).toContainText('stable channel')

        await card(page).getByRole('button', { name: 'Check for updates' }).click()

        await expect(toast(page)).toContainText('Coresmith v9.9.9 is available.')
        await expect(card(page)).toContainText('Update available')
        await expect(card(page)).toContainText('v9.9.9 is available')
        await expect(card(page)).toContainText('Faster deploys on large repositories.')
        await expect(card(page).getByRole('link', { name: 'Release notes' })).toHaveAttribute(
            'href',
            'https://github.com/monoven/coresmith/releases/tag/v9.9.9'
        )
    })

    test('an update is confirmed, requested and cancelled before it starts', async ({ page }) => {
        await available(page)

        await card(page).getByRole('button', { name: 'Update now' }).click()
        await expect(dialog(page).getByRole('heading', { name: 'Update to v9.9.9?' })).toBeVisible()
        await dialog(page).getByRole('button', { name: 'Not now' }).click()
        await expect(dialog(page)).toBeHidden()

        await card(page).getByRole('button', { name: 'Update now' }).click()
        await dialog(page).getByRole('button', { name: 'Update', exact: true }).click()

        await expect(toast(page)).toContainText('Update requested.')
        await expect(card(page)).toContainText('Waiting for the update service to start.')
        expect(existsSync(`${STATE}/request.json`)).toBe(true)

        await card(page).getByRole('button', { name: 'Cancel request' }).click()

        await expect(toast(page)).toContainText('Request cancelled.')
        await expect(card(page).getByRole('button', { name: 'Update now' })).toBeVisible()
        expect(existsSync(`${STATE}/request.json`)).toBe(false)
    })

    test('a running update shows its step, its log and survives the restart', async ({ page }) => {
        await running(page)

        await expect(card(page)).toContainText('Updating to v9.9.9')
        await expect(card(page)).toContainText('Installing PHP dependencies · step 4 of 12')
        await expect(card(page).getByTestId('update-terminal')).toContainText('Fetching the release')
        await expect(card(page).getByTestId('update-terminal')).toContainText('Installing PHP dependencies')

        await page.route('**/settings/updates/status', (route) => route.fulfill({ status: 503 }))

        await expect(card(page)).toContainText('Restarting on v9.9.9. This page reconnects by itself.')

        await page.unroute('**/settings/updates/status')
        holder?.kill('SIGKILL')
        holder = null
        writeStatus('succeeded', 'health', {
            message: 'Coresmith v9.9.9 is running. Backup taken, health check passed.',
            markers: {
                built: '9.9.9-a1b2c3d',
                maintenance: true,
                backup: null,
                migrating: true,
                switched: true,
                healthy: true
            }
        })

        await expect(toast(page)).toContainText('Updated.', { timeout: 15_000 })
    })

    test('a rolled back update says which step failed and shows its log', async ({ page }) => {
        await rolledBack(page)

        await expect(card(page).getByTestId('update-failed')).toContainText(
            'The update to v9.9.9 failed while running migrations, so Coresmith rolled back to v0.1.0.'
        )

        await card(page).getByRole('button', { name: 'View log' }).click()

        await expect(dialog(page).getByRole('heading', { name: 'Update log' })).toBeVisible()
        await expect(dialog(page).getByTestId('update-log')).toContainText('SQLSTATE[23000]: UNIQUE constraint failed')

        await dialog(page).getByRole('button', { name: 'Close' }).first().click()
        await expect(dialog(page)).toBeHidden()
    })

    test('an update that stopped before changing anything can be tried again', async ({ page }) => {
        writeStatus('aborted', 'preflight', {
            failed_step: 'preflight',
            message: 'A deploy of atlas is running. Wait for it to finish, then update.'
        })
        await gotoSettings(page, 'General')

        const banner = card(page).getByTestId('update-failed')

        await expect(banner).toContainText('The update to v9.9.9 stopped before changing anything.')
        await expect(banner).toContainText('A deploy of atlas is running. Wait for it to finish, then update.')

        await banner.getByRole('button', { name: 'Try again' }).click()

        await expect(dialog(page).getByRole('heading', { name: 'Update to v9.9.9?' })).toBeVisible()

        await dialog(page).getByRole('button', { name: 'Not now' }).click()
        await expect(dialog(page)).toBeHidden()
    })

    test('an update whose rollback did not finish says so and offers its log, not another try', async ({ page }) => {
        writeStatus(
            'failed',
            'rollback',
            {
                failed_step: 'migrate',
                message:
                    'Running migrations failed: x. The rollback did not finish: the backup is missing. The panel is in maintenance mode.'
            },
            ['Running migrations', 'Rolling back']
        )
        await gotoSettings(page, 'General')

        const banner = card(page).getByTestId('update-failed')

        await expect(banner).toContainText("The update to v9.9.9 failed and the rollback didn't finish.")
        await expect(banner).toContainText('The rollback did not finish: the backup is missing.')
        await expect(banner.getByRole('button', { name: 'View log' })).toBeVisible()
        await expect(banner.getByRole('button', { name: 'Try again' })).toHaveCount(0)
        await expect(banner.getByRole('button', { name: 'Recover' })).toHaveCount(0)
    })

    test('an interrupted update is recovered from the card', async ({ page }) => {
        writeStatus('running', 'dependencies')
        await gotoSettings(page, 'General')

        const banner = card(page).getByTestId('update-failed')

        await expect(banner).toContainText('The update to v9.9.9 stopped partway through installing PHP dependencies.')

        await banner.getByRole('button', { name: 'Recover' }).click()

        await expect(toast(page)).toContainText('Recovering. This takes about a minute.')
        expect(JSON.parse(readFileSync(`${STATE}/request.json`, 'utf8')).trigger).toBe('recover')
    })

    test('a request the update service has not taken for half a minute says what to check', async ({ page }) => {
        writeRequest('request.json', 31)
        await gotoSettings(page, 'General')

        await expect(card(page).getByTestId('update-progress')).toContainText(
            "The update service hasn't started. Check coresmith-update.path is enabled, or run the update from the shell."
        )
        await expect(card(page).getByRole('button', { name: 'Cancel request' })).toBeVisible()
    })

    test('a request the update service took but never began can be cancelled', async ({ page }) => {
        writeRequest('request.taken.json', 301)
        await gotoSettings(page, 'General')

        await expect(card(page).getByTestId('update-progress')).toContainText(
            "The update service took the request but didn't start the update. Cancel the request, then try again or run the update from the shell."
        )

        await card(page).getByRole('button', { name: 'Cancel request' }).click()

        await expect(toast(page)).toContainText('Request cancelled.')
        expect(existsSync(`${STATE}/request.taken.json`)).toBe(false)
    })

    test('the card and dialog buttons are 44 pixels tall on phones and keep their size elsewhere', async ({
        page
    }, testInfo) => {
        const phone = isMobile(testInfo)

        await rolledBack(page)

        expect(await height(page, 'Check for updates', 'card')).toBe(phone ? 44 : 36)

        await card(page).getByRole('button', { name: 'View log' }).click()

        expect(await height(page, 'Close', 'dialog')).toBe(phone ? 44 : 40)

        await dialog(page).getByRole('button', { name: 'Close' }).click()
        await expect(dialog(page)).toBeHidden()
        await card(page).getByRole('button', { name: 'Try again' }).click()

        expect(await height(page, 'Not now', 'dialog')).toBe(phone ? 44 : 40)
        expect(await height(page, 'Update', 'dialog')).toBe(phone ? 44 : 40)
    })

    test('the automatic patch setting is saved and kept', async ({ page }) => {
        await gotoSettings(page, 'General')

        const toggle = card(page).getByTestId('auto-patch').getByRole('switch')
        const before = await toggle.getAttribute('aria-checked')

        await toggle.click()
        await expect(toast(page)).toContainText('Saved.')

        await page.reload()

        const reloaded = card(page).getByTestId('auto-patch').getByRole('switch')

        await expect(reloaded).not.toHaveAttribute('aria-checked', before ?? 'false')

        await reloaded.click()
        await expect(toast(page)).toContainText('Saved.')
        await expect(card(page).getByTestId('auto-patch').getByRole('switch')).toHaveAttribute(
            'aria-checked',
            before ?? 'false'
        )
    })
})

test.describe('panel updates accessibility', () => {
    test.use({ reducedMotion: 'reduce' })

    for (const theme of ['light', 'dark'] as const) {
        test(`the card has no serious accessibility problems in ${theme}`, async ({ page }) => {
            await useTheme(page, theme)
            await available(page)

            expect(await blockingViolations(page)).toEqual([])

            rmSync(STATE, { recursive: true, force: true })
            await rolledBack(page)

            expect(await blockingViolations(page)).toEqual([])

            await card(page).getByRole('button', { name: 'View log' }).click()
            await expect(dialog(page).getByTestId('update-log')).toBeVisible()

            expect(await blockingViolations(page)).toEqual([])
        })

        test(`a running update has no serious accessibility problems in ${theme}`, async ({ page }) => {
            await useTheme(page, theme)
            await running(page)

            await expect(card(page).getByTestId('update-terminal')).toBeVisible()

            expect(await blockingViolations(page)).toEqual([])
        })
    }
})

asMember.describe('panel updates for a deployer', () => {
    asMember('a deployer sees the version but no controls', async ({ page }) => {
        await gotoSettings(page, 'General')

        await expect(card(page)).toContainText('Coresmith v')
        await expect(card(page).getByRole('button', { name: 'Check for updates' })).toHaveCount(0)
        await expect(card(page).getByTestId('auto-patch')).toHaveCount(0)
        await expect(card(page).getByRole('button', { name: 'Update now' })).toHaveCount(0)
    })
})
