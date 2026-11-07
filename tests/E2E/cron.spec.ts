// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

import type { Page, TestInfo } from '@playwright/test'
import { dialog, expect, gotoApplication, noSidewaysScroll, own, test, toast } from './support/fixtures'

function cronCard(page: Page) {
    return page.getByTestId('cron-jobs')
}

function cronRow(page: Page, title: string) {
    return cronCard(page).getByTestId('cron-job').filter({ hasText: title })
}

function tag(testInfo: TestInfo, name: string) {
    return `${own(testInfo, name)}-${testInfo.retry}`
}

async function addJob(page: Page, command: string, when?: () => Promise<void>) {
    await cronCard(page).getByRole('button', { name: 'Add cron job' }).click()
    await dialog(page).getByLabel('Command').fill(command)
    await when?.()
    await dialog(page).getByRole('button', { name: 'Add job' }).click()
    await expect(toast(page)).toContainText('Saved.')
}

async function removeJob(page: Page, title: string) {
    await cronCard(page)
        .getByRole('button', { name: `Remove ${title}` })
        .click()
    await dialog(page).getByRole('button', { name: 'Remove the job' }).click()
    await expect(toast(page)).toContainText('Removed.')
    await expect(cronRow(page, title)).toHaveCount(0)
}

function schedulerSwitch(page: Page) {
    return page.getByTestId('smart-toggles').getByRole('switch', { name: 'Scheduler' })
}

const PRESETS = [
    { value: 'minute', sentence: 'Every minute', fill: async () => undefined },
    {
        value: 'hourly',
        sentence: 'Every hour at :15',
        fill: (page: Page) => dialog(page).getByLabel('Minute past the hour').fill('15')
    },
    {
        value: 'daily',
        sentence: 'Daily at 3:00 AM UTC',
        fill: (page: Page) => dialog(page).getByLabel('Time, UTC').fill('03:00')
    },
    {
        value: 'weekly',
        sentence: 'Weekly on Monday at 8:30 AM UTC',
        fill: async (page: Page) => {
            await dialog(page).getByLabel('Day', { exact: true }).selectOption('1')
            await dialog(page).getByLabel('Time, UTC').fill('08:30')
        }
    }
] as const

test.describe('cron jobs on the app page', () => {
    test.skip(
        ({ isMobile }) => isMobile,
        'The phone app page keeps only Deploy, the toggles and the current release, as the mobile prototype draws it'
    )

    test('each job is listed with its schedule in words, its fields and its command', async ({ page }) => {
        await gotoApplication(page, 'bench-01', 'cron-jobs')

        await expect(cronCard(page).getByRole('heading', { name: 'Cron jobs' })).toBeVisible()
        await expect(cronRow(page, 'Laravel scheduler')).toContainText('Every minute')
        await expect(cronRow(page, 'Laravel scheduler')).toContainText('php artisan schedule:run')
        await expect(cronRow(page, 'Nightly backup')).toContainText('Daily at 3:00 AM UTC')
        await expect(cronRow(page, 'Nightly backup')).toContainText('0 3 * * *')
        await expect(cronRow(page, 'php artisan report:weekly')).toContainText('Weekly on Monday at 8:30 AM UTC')
        await expect(cronRow(page, 'Price sync')).toContainText('Custom schedule')
        await expect(cronRow(page, 'Price sync')).toContainText('15 9-17 * * 1-5')
        await expect(cronRow(page, 'Price sync')).toContainText('php artisan prices:sync…')
        await expect(cronCard(page)).toContainText("Times are UTC, the server's clock.")
    })

    test('an app with no cron jobs says how to add one', async ({ page }) => {
        await gotoApplication(page, 'bench-01', 'hungry')

        await expect(cronCard(page)).toContainText('No cron jobs yet. Add one to run a command on a schedule.')
    })

    test('a stopped app says its jobs are paused', async ({ page }) => {
        await gotoApplication(page, 'bench-01', 'cron-paused')

        await expect(page.getByTestId('cron-note')).toHaveText(
            'Paused while the app is stopped. The jobs run again when you start it.'
        )
    })

    test('an app that was never deployed says its jobs start with the first deploy', async ({ page }) => {
        await gotoApplication(page, 'bench-01', 'cron-new')

        await expect(page.getByTestId('cron-note')).toHaveText(
            'Nothing is live yet, so these jobs start with the first deploy.'
        )
        await expect(cronCard(page).getByRole('button', { name: 'Show output' })).toHaveCount(0)
    })

    test('a change that has waited too long says the queue may be stopped', async ({ page }) => {
        await gotoApplication(page, 'bench-01', 'cron-waiting')

        await expect(page.getByTestId('cron-status')).toHaveText(
            "Still waiting to reach bench-01. The panel's queue may be stopped, and the next deploy writes the cron file too."
        )
    })

    test('the scheduler log shows its newest lines and scrolls back from the keyboard', async ({ page }) => {
        await gotoApplication(page, 'bench-01', 'cron-jobs')

        await cronCard(page).getByRole('button', { name: 'Show output' }).click()

        const log = page.getByTestId('scheduler-log')
        const newest = log.getByText('[2026-11-06 09:00:00] Running [prune] .... DONE')
        const first = log.getByText('[2026-11-06 08:11:00] Running [schedule:run] first line')
        const long = log.getByText(/^\[2026-11-06 08:58:00\] prices:sync/)

        await expect(log).toHaveAttribute('role', 'log')
        await expect(newest).toBeAttached()
        await log.scrollIntoViewIfNeeded()
        await expect(newest).toBeInViewport()
        await expect(first).not.toBeInViewport()
        await expect(log).toContainText('Running [report:weekly]\tqueued')
        expect((await long.boundingBox())?.height ?? 0).toBeGreaterThan(30)

        await log.focus()
        await expect(log).toBeFocused()
        await page.keyboard.press('Home')

        await expect(first).toBeInViewport()

        await cronCard(page).getByRole('button', { name: 'Hide output' }).click()
        await expect(log).toHaveCount(0)
    })

    test('a scheduler log that could not be read says so', async ({ page }) => {
        await gotoApplication(page, 'bench-01', 'cron-paused')

        await cronCard(page).getByRole('button', { name: 'Show output' }).click()

        await expect(page.getByTestId('scheduler-log')).toHaveText("Couldn't read the scheduler log on bench-01.")
    })

    test('an app at its cron job limit says so and offers no way to add another', async ({ page }) => {
        await gotoApplication(page, 'bench-01', 'cron-full')

        await expect(cronCard(page)).toContainText('20 of 20 jobs. Remove one to add another.')
        await expect(cronCard(page).getByRole('button', { name: 'Add cron job' })).toBeDisabled()
    })

    test('cron jobs that did not reach the server say why and can be sent again', async ({ page }, testInfo) => {
        await gotoApplication(page, 'bench-01', own(testInfo, 'cron-failed'))

        const banner = page.getByTestId('cron-failed-banner')

        await expect(banner).toContainText('The cron jobs did not reach bench-01.')
        await expect(banner).toContainText("Couldn't reach bench-01 over SSH. Check it's online, then try again.")

        await banner.getByRole('button', { name: 'Try again' }).click()

        await expect(toast(page)).toContainText('Sending the cron jobs to bench-01 again.')
        await expect(banner).toHaveCount(0)
        await expect(page.getByTestId('cron-status')).toHaveText('Writing the cron file on bench-01…')
    })
})

test.describe('the cron banner on a phone', () => {
    test.skip(({ isMobile }) => !isMobile, 'The phone layout and tap sizes apply only below 640px')

    test('the banner that says the cron jobs did not reach the server can be tapped', async ({ page }) => {
        await gotoApplication(page, 'bench-01', 'cron-failed')
        await noSidewaysScroll(page)

        const retry = page.getByTestId('cron-failed-banner').getByRole('button', { name: 'Try again' })

        expect((await retry.boundingBox())?.height ?? 0).toBeGreaterThanOrEqual(44)
        await expect(page.getByTestId('cron-jobs')).toBeHidden()
    })
})

test.describe('changing cron jobs', () => {
    test.skip(
        ({ isMobile }) => isMobile,
        'The phone app page keeps only Deploy, the toggles and the current release, as the mobile prototype draws it'
    )

    for (const preset of PRESETS) {
        test(`a job added with the ${preset.value} preset says when it runs in words`, async ({ page }, testInfo) => {
            const command = `echo ${tag(testInfo, preset.value)}`

            await gotoApplication(page, 'bench-01', own(testInfo, 'cron'))
            await addJob(page, command, async () => {
                await dialog(page).getByLabel('Runs').selectOption(preset.value)
                await preset.fill(page)
            })

            await expect(cronRow(page, command)).toContainText(preset.sentence)
            await expect(page.getByTestId('cron-status')).toHaveText('Writing the cron file on bench-01…')
            await removeJob(page, command)
        })
    }

    test('a job is changed in place, starting from what it was', async ({ page }, testInfo) => {
        const command = `echo ${tag(testInfo, 'edit')}`
        const description = `Ten minute check ${tag(testInfo, 'edit')}`

        await gotoApplication(page, 'bench-01', own(testInfo, 'cron'))
        await addJob(page, command, async () => {
            await dialog(page).getByLabel('Runs').selectOption('daily')
            await dialog(page).getByLabel('Time, UTC').fill('04:30')
        })

        await cronCard(page)
            .getByRole('button', { name: `Edit ${command}` })
            .click()
        await expect(dialog(page)).toContainText('Edit cron job')
        await expect(dialog(page).getByLabel('Command')).toHaveValue(command)
        await expect(dialog(page).getByLabel('Runs')).toHaveValue('daily')
        await expect(dialog(page).getByLabel('Time, UTC')).toHaveValue('04:30')

        await dialog(page).getByLabel('Runs').selectOption('custom')
        await expect(dialog(page).getByLabel('Expression')).toHaveValue('30 4 * * *')
        await dialog(page).getByLabel('Expression').fill('*/10 * * * *')
        await dialog(page).getByLabel('Description').fill(description)
        await dialog(page).getByRole('button', { name: 'Save' }).click()

        await expect(toast(page)).toContainText('Saved.')
        await expect(cronRow(page, description)).toContainText('Every 10 minutes')
        await expect(cronRow(page, description)).toContainText(command)
        await removeJob(page, description)
    })

    test('removing a job asks first, and the job can be kept', async ({ page }, testInfo) => {
        const command = `echo ${tag(testInfo, 'keep')}`

        await gotoApplication(page, 'bench-01', own(testInfo, 'cron'))
        await addJob(page, command)

        await cronCard(page)
            .getByRole('button', { name: `Remove ${command}` })
            .click()
        await expect(dialog(page)).toContainText('Remove this cron job?')
        await expect(dialog(page)).toContainText(
            `${command} stops running on bench-01 as soon as the change reaches it.`
        )
        await dialog(page).getByRole('button', { name: 'Keep it' }).click()

        await expect(dialog(page)).toHaveCount(0)
        await expect(cronRow(page, command)).toBeVisible()
        await removeJob(page, command)
    })

    test('removing a job another tab already removed says the list changed and saves nothing', async ({
        page
    }, testInfo) => {
        const command = `echo ${tag(testInfo, 'stale')}`
        const app = own(testInfo, 'cron')

        await gotoApplication(page, 'bench-01', app)
        await addJob(page, command)

        await cronCard(page)
            .getByRole('button', { name: `Remove ${command}` })
            .click()
        await expect(dialog(page)).toContainText('Remove this cron job?')

        const other = await page.context().newPage()

        await gotoApplication(other, 'bench-01', app)
        await removeJob(other, command)
        await other.close()

        await dialog(page).getByRole('button', { name: 'Remove the job' }).click()

        await expect(toast(page)).toContainText(
            'That cron job changed since this page loaded, so nothing was saved. Check the list and try again.'
        )
        await expect(cronRow(page, command)).toHaveCount(0)
    })

    test('a custom expression is kept as written', async ({ page }, testInfo) => {
        const command = `echo ${tag(testInfo, 'custom')}`

        await gotoApplication(page, 'bench-01', own(testInfo, 'cron'))
        await addJob(page, command, async () => {
            await dialog(page).getByLabel('Runs').selectOption('custom')
            await dialog(page).getByLabel('Expression').fill('15 9-17 * * 1-5')
        })

        await expect(cronRow(page, command)).toContainText('Custom schedule')
        await expect(cronRow(page, command)).toContainText('15 9-17 * * 1-5')
        await removeJob(page, command)
    })

    test('a schedule cron cannot run is refused with what to fix', async ({ page }, testInfo) => {
        const command = `echo ${tag(testInfo, 'refused')}`
        const expression = dialog(page).getByLabel('Expression')

        await gotoApplication(page, 'bench-01', own(testInfo, 'cron'))
        await cronCard(page).getByRole('button', { name: 'Add cron job' }).click()
        await dialog(page).getByLabel('Command').fill(command)
        await dialog(page).getByLabel('Runs').selectOption('custom')
        await expression.fill('61 * * * *')
        await dialog(page).getByRole('button', { name: 'Add job' }).click()

        await expect(dialog(page)).toContainText("61 isn't a minute. Minutes run from 0 to 59.")
        await expect(expression).toHaveAttribute('aria-invalid', 'true')

        await expression.fill('*/5 * * * *')
        await expect(expression).not.toHaveAttribute('aria-invalid', 'true')
        await dialog(page).getByRole('button', { name: 'Add job' }).click()

        await expect(cronRow(page, command)).toContainText('Every 5 minutes')
        await removeJob(page, command)
    })

    test('the Scheduler toggle and the list are one entry', async ({ page }, testInfo) => {
        await gotoApplication(page, 'bench-01', own(testInfo, 'cron-toggle'))

        if (!(await schedulerSwitch(page).isChecked())) {
            await schedulerSwitch(page).click()
            await expect(schedulerSwitch(page)).toBeChecked()
        }

        await expect(cronRow(page, 'Laravel scheduler')).toContainText('Every minute')

        await schedulerSwitch(page).click()
        await expect(schedulerSwitch(page)).not.toBeChecked()
        await expect(cronRow(page, 'Laravel scheduler')).toHaveCount(0)

        await schedulerSwitch(page).click()
        await expect(schedulerSwitch(page)).toBeChecked()
        await expect(cronRow(page, 'Laravel scheduler')).toBeVisible()

        await cronCard(page).getByRole('button', { name: 'Remove Laravel scheduler' }).click()
        await expect(dialog(page)).toContainText('and the Scheduler toggle turns off.')
        await dialog(page).getByRole('button', { name: 'Remove the job' }).click()

        await expect(toast(page)).toContainText('The Scheduler toggle is off now.')
        await expect(schedulerSwitch(page)).not.toBeChecked()
        await expect(cronRow(page, 'Laravel scheduler')).toHaveCount(0)

        await schedulerSwitch(page).click()
        await expect(cronRow(page, 'Laravel scheduler')).toBeVisible()
    })
})
