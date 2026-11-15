import type { Page, TestInfo } from '@playwright/test'
import {
    asMember,
    blockingViolations,
    dialog,
    expect,
    gotoApplication,
    gotoServerTab,
    gotoSettings,
    isMobile,
    test,
    toast,
    useTheme,
    withProps
} from './support/fixtures'

type Mine = {
    kind: string
    name: string
    labels: [string, string]
    change: [string, string]
    rule: string
    back: [string, string]
    defaultRule: string
    server: string
}

const MINE: Record<string, Mine> = {
    chromium: {
        kind: 'disk',
        name: 'Disk space',
        labels: ['Alert above', 'For'],
        change: ['85%', 'For 5 minutes'],
        rule: 'Above 85% used for 5 minutes, and again at 95%, where deploys stop',
        back: ['80%', 'As soon as seen'],
        defaultRule: 'Above 80% used, as soon as a reading shows it, and again at 95%, where deploys stop',
        server: 'forge-02'
    },
    firefox: {
        kind: 'memory',
        name: 'Memory',
        labels: ['Alert above', 'For'],
        change: ['90%', 'For 10 minutes'],
        rule: 'Above 90% used for 10 minutes',
        back: ['95%', 'For 4 minutes'],
        defaultRule: 'Above 95% used for 4 minutes',
        server: 'anvil-01'
    },
    webkit: {
        kind: 'cpu',
        name: 'Processor',
        labels: ['Alert above', 'For'],
        change: ['80%', 'For 30 minutes'],
        rule: 'Above 80% busy for 30 minutes',
        back: ['90%', 'For 10 minutes'],
        defaultRule: 'Above 90% busy for 10 minutes',
        server: 'bench-01'
    },
    'mobile-chrome': {
        kind: 'load',
        name: 'Load',
        labels: ['Alert above', 'For'],
        change: ['2 per core', 'For 30 minutes'],
        rule: 'Above 2 per core for 30 minutes',
        back: ['1.5 per core', 'For 15 minutes'],
        defaultRule: 'Above 1.5 per core for 15 minutes',
        server: 'hot-01'
    },
    'mobile-safari': {
        kind: 'apps',
        name: 'Apps answering',
        labels: ['Alert on', 'After'],
        change: ['Any error', 'Three checks in a row'],
        rule: "An app's domain doesn't answer, or answers with any error, three checks in a row",
        back: ['Server errors', 'Two checks in a row'],
        defaultRule: "An app's domain doesn't answer, or answers with a server error, two checks in a row",
        server: 'outpost-01'
    }
}

function mine(testInfo: TestInfo): Mine {
    return MINE[testInfo.project.name] ?? MINE.chromium
}

function card(page: Page) {
    return page.getByTestId('monitors-card')
}

async function changeTo(page: Page, monitor: Mine, [level, duration]: [string, string]) {
    await card(page)
        .getByRole('button', { name: `Change the limit for ${monitor.name}` })
        .click()
    await expect(
        dialog(page).getByRole('heading', { name: `Change the ${monitor.name.toLowerCase()} monitor` })
    ).toBeVisible()
    await dialog(page).getByLabel(monitor.labels[0]).selectOption({ label: level })
    await dialog(page).getByLabel(monitor.labels[1]).selectOption({ label: duration })
    await dialog(page).getByRole('button', { name: 'Save' }).click()
    await expect(toast(page)).toContainText('Saved.')
    await expect(dialog(page)).toHaveCount(0)
}

test.describe('Monitors in Settings, Notifications', () => {
    test('every monitor is listed with the rule it follows, under the email preferences', async ({ page }) => {
        await gotoSettings(page, 'Notifications')

        await expect(card(page).getByRole('heading', { name: 'Monitors' })).toBeVisible()

        for (const [kind, name] of [
            ['disk', 'Disk space'],
            ['memory', 'Memory'],
            ['cpu', 'Processor'],
            ['load', 'Load'],
            ['apps', 'Apps answering']
        ]) {
            const row = page.getByTestId(`monitor-${kind}`)

            await expect(row).toContainText(name)
            await expect(row.getByRole('switch')).toBeChecked()
            await expect(row.getByRole('button', { name: `Change the limit for ${name}` })).toBeEnabled()
        }

        await expect(page.getByTestId('monitor-memory')).toContainText('Above 95% used for 4 minutes')
        await expect(card(page)).toContainText('Every server follows the limits above.')
        await expect(card(page)).toContainText(
            'Owners and admins can change monitors. Everyone else sees them. A change applies from the next reading.'
        )
    })

    test('Cron jobs has a switch and nothing to change', async ({ page }) => {
        await gotoSettings(page, 'Notifications')

        const row = page.getByTestId('monitor-cron')

        await expect(row).toContainText('Cron jobs')
        await expect(row).toContainText("A job exits with an error, or doesn't run within 2 minutes of when it's due")
        await expect(row.getByRole('switch')).toBeChecked()
        await expect(row.getByRole('button', { name: /Change/ })).toHaveCount(0)
    })

    test('a monitor switched off says so, and switches back on', async ({ page }, testInfo) => {
        const monitor = mine(testInfo)

        await gotoSettings(page, 'Notifications')

        const toggle = page.getByTestId(`monitor-${monitor.kind}`).getByRole('switch')

        await toggle.click()
        await expect(toast(page)).toContainText('Saved.')
        await expect(toggle).not.toBeChecked()

        await toggle.click()
        await expect(toggle).toBeChecked()
    })

    test('Change sets a new line and how long it must last, and the row reads the new rule', async ({
        page
    }, testInfo) => {
        const monitor = mine(testInfo)
        const row = page.getByTestId(`monitor-${monitor.kind}`)

        await gotoSettings(page, 'Notifications')
        await changeTo(page, monitor, monitor.change)
        await expect(row).toContainText(monitor.rule)

        await changeTo(page, monitor, monitor.back)
        await expect(row).toContainText(monitor.defaultRule)
    })

    test('a server can have its own limit, listed under the monitors, and lose it again', async ({
        page
    }, testInfo) => {
        const { server } = mine(testInfo)

        await gotoSettings(page, 'Notifications')
        await card(page).getByRole('button', { name: 'Set limits for a server' }).click()
        await expect(dialog(page).getByRole('heading', { name: 'Set limits for a server' })).toBeVisible()
        await dialog(page).getByLabel('Server').selectOption({ label: server })
        await dialog(page).getByLabel('Monitor').selectOption({ label: 'Processor' })
        await dialog(page).getByLabel('Alert above').selectOption({ label: '95%' })
        await dialog(page).getByLabel('For').selectOption({ label: 'For 30 minutes' })
        await dialog(page).getByRole('button', { name: 'Save' }).click()
        await expect(toast(page)).toContainText('Saved.')

        const override = card(page).getByTestId('monitor-override').filter({ hasText: server })

        await expect(override).toContainText('Processor above 95% busy for 30 minutes')

        await override.getByRole('button', { name: `Remove the processor limit for ${server}` }).click()
        await expect(toast(page)).toContainText(`Removed. ${server} follows the team's limit again.`)
        await expect(card(page).getByTestId('monitor-override').filter({ hasText: server })).toHaveCount(0)
    })

    test('a plan without monitor limits says so, and only the switches still work', async ({ page }) => {
        await withProps(page, /\/settings\/notifications$/, (props) => {
            const monitors = props.monitors as Record<string, unknown>

            monitors.plan = false
            monitors.footnote =
                'These defaults run on every plan. Changing them, and limits for one server, are part of Pro and Business.'
        })
        await gotoSettings(page, 'Notifications')

        await expect(card(page)).toContainText('Limits change on Pro')
        await expect(card(page).getByRole('button', { name: 'Change the limit for Processor' })).toBeDisabled()
        await expect(card(page).getByRole('button', { name: 'Set limits for a server' })).toBeDisabled()
        await expect(page.getByTestId('monitor-cpu').getByRole('switch')).toBeEnabled()
        await expect(card(page)).toContainText('part of Pro and Business')
    })

    test('the card fits the screen and passes axe in light and dark, with its dialog open too', async ({ page }) => {
        for (const theme of ['light', 'dark'] as const) {
            await useTheme(page, theme)
            await gotoSettings(page, 'Notifications')
            await expect(card(page)).toBeVisible()

            expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true)
            expect(await blockingViolations(page)).toEqual([])

            await card(page).getByRole('button', { name: 'Change the limit for Memory' }).click()
            await expect(dialog(page)).toBeVisible()
            expect(await blockingViolations(page)).toEqual([])
            await dialog(page).getByRole('button', { name: 'Cancel' }).click()
        }
    })
})

asMember('a member sees the monitors but cannot change them', async ({ page }) => {
    await gotoSettings(page, 'Notifications')

    await expect(card(page).getByRole('button', { name: 'Change the limit for Disk space' })).toBeDisabled()
    await expect(page.getByTestId('monitor-disk').getByRole('switch')).toBeDisabled()
    await expect(card(page).getByRole('button', { name: 'Set limits for a server' })).toBeDisabled()
})

test.describe('Alerts on a server', () => {
    test('the Alerts filter keeps alerts and their recoveries, and Everything brings the rest back', async ({
        page
    }) => {
        await gotoServerTab(page, 'outpost-01', 'Activity')

        const activity = page.getByTestId('server-activity')
        const show = page.getByRole('navigation', { name: 'Show' })

        await expect(activity).toContainText('provisioned and hardened')

        await show.getByRole('link', { name: 'Alerts' }).click()
        await expect(show.getByRole('link', { name: 'Alerts' })).toHaveAttribute('aria-current', 'page')
        await expect(activity).toContainText('Processor warning, outpost-01 at 96% for 10 minutes')
        await expect(activity).toContainText('Processor back to normal on outpost-01 after 22 minutes')
        await expect(activity).toContainText('relay-edge answered 502 at edge.relay.monoven.io')
        await expect(activity).toContainText('relay-edge answering again after 15 minutes')
        await expect(activity).toContainText('Disk warning, outpost-01 at 84%')
        await expect(activity).not.toContainText('provisioned and hardened')
        await expect(page.getByText('Alerts follow the limits in')).toBeVisible()

        await show.getByRole('link', { name: 'Everything' }).click()
        await expect(show.getByRole('link', { name: 'Everything' })).toHaveAttribute('aria-current', 'page')
        await expect(activity).toContainText('provisioned and hardened')
    })

    test('the alerts view passes axe in light and dark', async ({ page }) => {
        for (const theme of ['light', 'dark'] as const) {
            await useTheme(page, theme)
            await gotoServerTab(page, 'outpost-01', 'Activity')
            await page.getByRole('navigation', { name: 'Show' }).getByRole('link', { name: 'Alerts' }).click()
            await expect(page.getByTestId('server-activity')).toContainText('Processor warning')

            expect(await blockingViolations(page)).toEqual([])
        }
    })
})

test.describe('Uptime on the app page', () => {
    test('an app checked for 25 days shows its uptime, with the days before the first check in grey', async ({
        page
    }, testInfo) => {
        await gotoApplication(page, 'forge-01', 'storefront')

        const uptime = page.getByTestId('app-uptime')

        if (isMobile(testInfo)) {
            await expect(page.getByTestId('domains-card')).toBeHidden()

            return
        }

        await expect(uptime).toContainText('99.97%')
        await expect(uptime).toContainText('answering over 30 days')
        await expect(uptime.getByRole('img')).toHaveAttribute(
            'aria-label',
            'Answering on each of the last 30 days: 24 with every check answered, 1 with some failed checks, 5 not checked'
        )
        await expect(uptime).toContainText(
            /Coresmith checks it every 5 minutes\. Last failed check on [A-Z][a-z]{2} \d{1,2} at 01:40\./
        )
    })

    test('an app never checked shows no uptime', async ({ page }) => {
        await gotoApplication(page, 'forge-01', 'atlas')

        await expect(page.getByTestId('app-uptime')).toHaveCount(0)
    })

    test('the uptime strip passes axe in light and dark', async ({ page }) => {
        for (const theme of ['light', 'dark'] as const) {
            await useTheme(page, theme)
            await gotoApplication(page, 'forge-01', 'storefront')

            expect(await blockingViolations(page)).toEqual([])
        }
    })
})
