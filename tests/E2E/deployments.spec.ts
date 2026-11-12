import type { Page } from '@playwright/test'
import {
    asMember,
    blockingViolations,
    expect,
    gotoApplication,
    isMobile,
    nav,
    noSidewaysScroll,
    own,
    test,
    toast,
    useTheme
} from './support/fixtures'

async function gotoDeployments(page: Page, mobile: boolean) {
    await page.goto('/dashboard')
    await nav(page)
        .getByRole('link', { name: mobile ? 'Deploys' : 'Deployments', exact: true })
        .click()
    await page.waitForURL(/\/deployments$/)
    await expect(page.getByRole('heading', { level: 1, name: 'Deployments' })).toBeVisible()
}

function filters(page: Page) {
    return page.getByRole('navigation', { name: 'Filter deployments' })
}

function rows(page: Page) {
    return page.getByTestId('deployment-row')
}

type Pushed = Record<string, unknown> & { id: string; log: string }

async function streamDeploys(page: Page) {
    let shown: Pushed | null = null
    let channel: string | null = null
    let send: ((message: string) => void) | null = null

    await page.route(
        (url) => /\/deployments\/[^/]+$/.test(url.pathname),
        async (route) => {
            const response = await route.fetch()

            if (!route.request().headers()['x-inertia']) {
                return route.fulfill({ response })
            }

            const visit = await response.json()
            shown = visit.props.deployment

            await route.fulfill({ response, json: { ...visit, props: { ...visit.props, streaming: true } } })
        }
    )

    await page.route('**/broadcasting/auth', (route) => route.fulfill({ json: { auth: 'coresmith-local:signature' } }))

    await page.routeWebSocket(/\/app\//, (socket) => {
        send = (message) => socket.send(message)
        socket.send(
            JSON.stringify({
                event: 'pusher:connection_established',
                data: JSON.stringify({ socket_id: '1234.5678', activity_timeout: 120 })
            })
        )
        socket.onMessage((message) => {
            const frame = JSON.parse(String(message))

            if (frame.event === 'pusher:subscribe') {
                channel = frame.data.channel
                socket.send(JSON.stringify({ event: 'pusher_internal:subscription_succeeded', channel, data: '{}' }))
            }
        })
    })

    return {
        async push(change: (deployment: Pushed) => Record<string, unknown>) {
            await expect
                .poll(() => channel !== null && shown !== null && channel === `private-deployments.${shown.id}`)
                .toBe(true)
            send?.(
                JSON.stringify({ event: 'deployment.updated', channel, data: JSON.stringify(change(shown as Pushed)) })
            )
        }
    }
}

test.describe('the deployments list', () => {
    test('deploys filter by project and environment', async ({ page }) => {
        await page.goto('/deployments')
        await page.getByLabel('Project').selectOption({ label: 'Storefront › Staging' })

        await page.waitForURL((url) => url.searchParams.has('project') && url.searchParams.has('environment'))
        await expect(rows(page).first()).toBeVisible()
        await expect(rows(page).filter({ hasNotText: 'storefront-staging' })).toHaveCount(0)
        await expect(page.getByLabel('Project')).toHaveValue(/:/)

        await filters(page)
            .getByRole('link', { name: /^Deployed/ })
            .click()
        await page.waitForURL(
            (url) =>
                url.searchParams.get('status') === 'deployed' &&
                url.searchParams.has('project') &&
                url.searchParams.has('environment')
        )
    })

    test('every deploy across the fleet is listed with the running one pinned on top', async ({ page }, testInfo) => {
        await gotoDeployments(page, isMobile(testInfo))

        await expect(filters(page).getByRole('link', { name: /^All\s*\d+$/ })).toHaveAttribute('aria-current', 'page')
        await expect(rows(page).first()).toContainText('atlas-staging')
        await expect(rows(page).first()).toContainText('Running')
        await expect(rows(page).first()).toContainText('Watch')
    })

    test('the failed filter shows only failures, and says they had no impact', async ({ page }, testInfo) => {
        await gotoDeployments(page, isMobile(testInfo))

        await filters(page)
            .getByRole('link', { name: /^Failed\s*\d+$/ })
            .click()
        await page.waitForURL(/status=failed/)

        await expect(rows(page).filter({ hasText: 'storefront' })).toHaveCount(1)

        for (const row of await rows(page).all()) {
            await expect(row).toContainText('Failed')
        }
    })

    test('the running filter shows the deploys in progress', async ({ page }, testInfo) => {
        await gotoDeployments(page, isMobile(testInfo))

        await filters(page)
            .getByRole('link', { name: /^Running\s*\d+$/ })
            .click()
        await page.waitForURL(/status=running/)

        await expect(rows(page).first()).toContainText('atlas-staging')

        for (const row of await rows(page).all()) {
            await expect(row).toContainText(/Running|Cancelling/)
        }
    })

    test('a deploy being cancelled keeps its dot and its Watch link', async ({ page }, testInfo) => {
        await gotoDeployments(page, isMobile(testInfo))

        const row = rows(page).filter({ hasText: 'stopping-app' }).first()

        await expect(row).toContainText('Cancelling')
        await expect(row).toContainText('Watch')
        await expect(row.locator('[data-slot="badge"] > span[aria-hidden="true"]')).toHaveCount(1)
    })

    test('a row opens its deploy and the back link returns to the list', async ({ page }, testInfo) => {
        await gotoDeployments(page, isMobile(testInfo))

        await rows(page).filter({ hasText: 'ledger' }).first().click()

        await expect(page.getByRole('heading', { level: 1, name: 'Deploying ledger' })).toBeVisible()
        await expect(page.getByTestId('back-link')).toHaveText('← Deployments')

        await page.getByTestId('back-link').click()
        await page.waitForURL(/\/deployments$/)
    })

    test('the list does not scroll sideways', async ({ page }, testInfo) => {
        await gotoDeployments(page, isMobile(testInfo))

        await noSidewaysScroll(page)
    })
})

test.describe('a deploy', () => {
    test('a running deploy shows each phase and refreshes without a socket', async ({ page }) => {
        await page.goto('/dashboard')
        await page.getByTestId('live-deploy').click()

        await expect(page.getByRole('heading', { level: 1, name: 'Deploying atlas-staging' })).toBeVisible()
        await expect(page.getByTestId('deployment-status')).toHaveText('Running')

        const rail = page.getByTestId('phase-rail')

        await expect(rail.locator('[data-phase]')).toHaveCount(6)
        await expect(rail.locator('[data-phase="clone"]')).toHaveAttribute('data-state', 'done')
        await expect(rail.locator('[data-phase][data-state="running"]')).toHaveCount(1)
        await expect(rail.locator('[data-phase="migrate"]')).toHaveAttribute('data-state', 'running')
        await expect(page.getByLabel('Deployment output')).toContainText('migrate: running migrations')
        await expect(page.getByTestId('log-transport')).toHaveText('Refreshing every 3s')
    })

    test('a log too long for the socket is fetched instead, and keeps coming every few seconds', async ({ page }) => {
        const stream = await streamDeploys(page)

        await page.goto('/dashboard')
        await page.getByTestId('live-deploy').click()

        const output = page.getByLabel('Deployment output')

        await stream.push((deployment) => ({
            ...deployment,
            log: `${deployment.log}\n[12:00:00] build: compiling assets`
        }))
        await expect(output).toContainText('build: compiling assets')
        await expect(page.getByTestId('log-transport')).toHaveText('Streaming live')

        await stream.push((deployment) => ({ ...deployment, log: null }))

        await expect(page.getByTestId('log-transport')).toHaveText('Refreshing every 3s')
        await expect(output).toContainText('migrate: running migrations')
        await expect(output).not.toContainText('build: compiling assets')
    })

    test('a failed deploy says which phase failed and that nothing changed', async ({ page }) => {
        await gotoApplication(page, 'forge-01', 'storefront')
        await page.getByTestId('failed-banner').getByRole('link', { name: 'Read the log' }).click()

        await expect(page.getByTestId('deployment-status')).toContainText('Failed')
        await expect(page.getByTestId('failure-banner')).toContainText('The build failed, so nothing changed.')
        await expect(page.getByTestId('failure-banner')).toContainText('users never saw an error page')
        await expect(page.getByTestId('phase-rail').locator('[data-phase="build"]')).toHaveAttribute(
            'data-state',
            'failed'
        )
        await expect(page.getByLabel('Deployment output')).toContainText(
            'deploy failed. the current release is untouched.'
        )
        await expect(page.getByTestId('log-transport')).toHaveText('Finished')
    })

    test('a failed deploy can be retried', async ({ page }, testInfo) => {
        const name = own(testInfo, 'deploy')

        await gotoApplication(page, 'bench-01', name)
        await page.getByTestId('failed-banner').getByRole('link', { name: 'Read the log' }).click()
        await page.getByRole('button', { name: 'Retry deploy' }).click()

        await expect(toast(page)).toContainText(`Deploying ${name}.`)
        await expect(page.getByTestId('deployment-status')).toHaveText('Queued')
        await expect(page.getByLabel('Deployment output')).toContainText('Waiting for a worker to pick up the deploy')
    })

    test('a finished deploy links to the releases it made', async ({ page }, testInfo) => {
        await gotoDeployments(page, isMobile(testInfo))

        const deployed = filters(page).getByRole('link', { name: /^Deployed\s*\d+$/ })

        await deployed.click()
        await expect(deployed).toHaveAttribute('aria-current', 'page')
        await rows(page).filter({ hasText: 'warehouse' }).first().click()

        await expect(page.getByTestId('deployment-status')).toHaveText('Deployed')
        await page.getByRole('link', { name: 'View releases' }).click()

        await expect(page.getByRole('heading', { level: 1, name: 'Releases · warehouse' })).toBeVisible()
    })

    test('the output can be focused so it scrolls from the keyboard', async ({ page }) => {
        await page.goto('/dashboard')
        await page.getByTestId('live-deploy').click()

        const output = page.getByLabel('Deployment output')

        await output.focus()
        await expect(output).toBeFocused()
    })

    test('a long log does not scroll the page sideways', async ({ page }) => {
        await gotoApplication(page, 'forge-01', 'storefront')
        await page.getByTestId('failed-banner').getByRole('link', { name: 'Read the log' }).click()

        await noSidewaysScroll(page)
    })
})

asMember.describe('deploys as a member', () => {
    asMember('a member can read a deploy log', async ({ page }) => {
        await page.goto('/dashboard')
        await page.getByTestId('live-deploy').click()

        await expect(page.getByLabel('Deployment output')).toContainText('clone: fetching via deploy key')
    })
})

test.describe('cancelling a deploy', () => {
    async function watch(page: Page, application: string) {
        await gotoApplication(page, 'cancel-01', application)
        await page.getByRole('link', { name: 'Watch deploy' }).click()
        await page.getByTestId('phase-rail').waitFor()
    }

    test('a running deploy can be cancelled and says it is stopping', async ({ page }, testInfo) => {
        test.skip(isMobile(testInfo), "The mobile prototype's running deploy has no Cancel")

        await watch(page, own(testInfo, 'cancel'))
        await page.getByRole('button', { name: 'Cancel', exact: true }).click()

        await expect(toast(page)).toContainText('Cancelling. The current release keeps serving.')
        await expect(page.getByRole('button', { name: 'Cancelling…' })).toBeDisabled()
        await expect(page.getByTestId('deployment-status')).toHaveText('Cancelling')
    })

    test('a cancel keeps saying it is stopping while the deploy streams', async ({ page }, testInfo) => {
        test.skip(isMobile(testInfo), "The mobile prototype's running deploy has no Cancel")

        const stream = await streamDeploys(page)

        await watch(page, own(testInfo, 'streamed'))
        await stream.push((deployment) => ({
            ...deployment,
            log: `${deployment.log}\n[12:00:00] build: compiling assets`
        }))
        await expect(page.getByLabel('Deployment output')).toContainText('build: compiling assets')

        await page.getByRole('button', { name: 'Cancel', exact: true }).click()

        await expect(toast(page)).toContainText('Cancelling. The current release keeps serving.')
        await expect(page.getByTestId('deployment-status')).toHaveText('Cancelling')
        await expect(page.getByRole('button', { name: 'Cancelling…' })).toBeDisabled()
    })

    test('on a phone a running deploy offers no cancel', async ({ page }, testInfo) => {
        test.skip(
            !isMobile(testInfo),
            'The desktop deploy page draws Cancel in its header, which the test above covers'
        )

        await watch(page, own(testInfo, 'cancel'))

        await expect(page.getByTestId('deployment-status')).toHaveText('Running')
        await expect(page.getByRole('button', { name: /^Cancel/ })).toHaveCount(0)
    })

    test('a queued deploy is cancelled at once', async ({ page }, testInfo) => {
        test.skip(isMobile(testInfo), "The mobile prototype's running deploy has no Cancel")

        await gotoDeployments(page, false)
        await rows(page)
            .filter({ hasText: own(testInfo, 'queued') })
            .first()
            .click()
        await page.getByTestId('phase-rail').waitFor()
        await page.getByRole('button', { name: 'Cancel', exact: true }).click()

        await expect(toast(page)).toContainText('Cancelled before it started. Nothing changed.')
        await expect(page.getByTestId('cancelled-strip')).toBeVisible()
        await expect(page.getByRole('button', { name: 'Deploy again' })).toBeVisible()
    })

    test('a deploy past its migrations offers no cancel', async ({ page }) => {
        await watch(page, 'migrating-app')

        await expect(page.getByTestId('deployment-status')).toHaveText('Running')
        await expect(page.getByRole('button', { name: /^Cancel/ })).toHaveCount(0)
    })

    test('a deploy already asked to stop says so and cannot be asked twice', async ({ page }, testInfo) => {
        test.skip(isMobile(testInfo), "The mobile prototype's running deploy has no Cancel")

        await watch(page, 'stopping-app')

        await expect(page.getByTestId('deployment-status')).toHaveText('Cancelling')
        await expect(page.getByRole('button', { name: 'Cancelling…' })).toBeDisabled()
    })

    test('a cancelled deploy says who stopped it and that nothing changed', async ({ page }, testInfo) => {
        await gotoDeployments(page, isMobile(testInfo))
        await rows(page).filter({ hasText: 'cancelled-app' }).first().click()

        const strip = page.getByTestId('cancelled-strip')
        const build = page.getByTestId('phase-rail').locator('[data-phase="build"]')

        await expect(strip).toContainText(
            'Maya Lindqvist cancelled this deploy, so nothing changed. The symlink still points at '
        )
        await expect(strip).toContainText(', users never saw a difference.')
        await expect(build).toHaveAttribute('data-state', 'cancelled')
        await expect(build).toContainText('cancelled')
        await expect(build.getByLabel('Cancelled')).toBeVisible()
        await expect(page.getByRole('button', { name: 'Deploy again' })).toBeVisible()
    })
})

test.describe('a deploy that failed with a known reason', () => {
    test('a release that crashed after going live says so and offers a roll back', async ({ page }) => {
        await gotoApplication(page, 'bench-01', 'crashy')

        await expect(page.getByTestId('failed-banner')).toContainText('The last deploy went live but did not finish.')

        await page.getByTestId('failed-banner').getByRole('link', { name: 'Read the log' }).click()

        await expect(page.getByTestId('failure-reason')).toContainText(
            'Crash loop: coresmith-app-crashy-fpm kept stopping'
        )
        await expect(page.getByTestId('phase-rail')).not.toContainText('failed')
        await expect(page.getByTestId('deployment-log')).toContainText('Class "App\\Billing\\Gateway" not found')

        await page.getByRole('link', { name: 'Roll back' }).filter({ visible: true }).first().click()
        await page.waitForURL(/\/releases$/)
    })

    test('a release whose processes did not start names the start, and every phase through the swap is done', async ({
        page
    }) => {
        await gotoApplication(page, 'bench-01', 'dev-mode')
        await page.getByTestId('failed-banner').getByRole('link', { name: 'Read the log' }).click()

        const reason = page.getByTestId('failure-reason')
        const rail = page.getByTestId('phase-rail')

        await expect(reason).toContainText('The processes did not start.')
        await expect(reason).toContainText(
            'coresmith-app-dev-mode-web did not start, systemd reports it as activating.'
        )
        await expect(reason).not.toContainText('The deploy failed after the swap.')
        await expect(rail.locator('[data-phase="swap"]')).toHaveAttribute('data-state', 'done')
        await expect(rail.locator('[data-phase][data-state="failed"]')).toHaveCount(0)
        await expect(rail).toContainText('the swap went through')
        await expect(page.getByTestId('deployment-log')).toContainText('watch: starting coresmith-app-dev-mode-web')
    })

    test('a worker that did not start is named on its deploy, and the swap still went through', async ({ page }) => {
        await gotoApplication(page, 'bench-01', 'no-sidekiq')
        await page.getByTestId('failed-banner').getByRole('link', { name: 'Read the log' }).click()

        const reason = page.getByTestId('failure-reason')
        const rail = page.getByTestId('phase-rail')

        await expect(reason).toContainText('Sidekiq did not start.')
        await expect(reason).toContainText(
            'coresmith-app-no-sidekiq-sidekiq did not start, systemd reports it as activating.'
        )
        await expect(reason).not.toContainText('The processes did not start.')
        await expect(rail.locator('[data-phase][data-state="failed"]')).toHaveCount(0)
        await expect(rail).toContainText('the swap went through')
    })

    test('a clone that failed names the branch it could not find', async ({ page }) => {
        await gotoApplication(page, 'bench-01', 'wrong-branch')
        await page.getByTestId('failed-banner').getByRole('link', { name: 'Read the log' }).click()

        await expect(page.getByTestId('failure-reason')).toContainText('The clone failed, so nothing changed.')
        await expect(page.getByTestId('failure-reason')).toContainText('Branch release/2.0 is not in the repository.')
        await expect(page.getByRole('link', { name: 'Roll back' })).toHaveCount(0)
    })

    test('a storage step that failed is named by that step, not by the clone', async ({ page }) => {
        await gotoApplication(page, 'bench-01', 'storage-copy')
        await page.getByTestId('failed-banner').getByRole('link', { name: 'Read the log' }).click()

        const reason = page.getByTestId('failure-reason')

        await expect(reason).toContainText("Preparing the app's storage failed, so nothing changed.")
        await expect(reason).toContainText("Fix the app's storage and push again, or retry.")
        await expect(reason).not.toContainText('clone')
        await expect(page.getByTestId('phase-rail').locator('[data-phase="clone"]')).toHaveAttribute(
            'data-state',
            'failed'
        )
        await expect(page.getByTestId('deployment-log')).toContainText(
            "Could not copy the live release's storage into shared/storage"
        )
    })
})

test.describe('a deploy that installs a runtime first', () => {
    test('the rail shows dependencies running and the log says what is compiling', async ({ page }) => {
        try {
            await page.goto('/acme/dashboard')
            await page.getByTestId('live-deploy').click()

            await expect(page.locator('[data-phase="deps"]')).toHaveAttribute('data-state', 'running')
            await expect(page.getByTestId('deployment-log')).toContainText(
                'installing PHP 8.5.10, compiling it, usually 5–15 minutes the first time'
            )
        } finally {
            await page.goto('/monoven/dashboard')
        }
    })

    for (const theme of ['light', 'dark'] as const) {
        test(`it has no serious or critical accessibility violations in ${theme}`, async ({ page }) => {
            try {
                await useTheme(page, theme)
                await page.goto('/acme/dashboard')
                await page.getByTestId('live-deploy').click()
                await page.getByTestId('phase-rail').waitFor()

                expect(await blockingViolations(page)).toEqual([])
            } finally {
                await page.goto('/monoven/dashboard')
            }
        })
    }
})
