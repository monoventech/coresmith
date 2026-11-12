import type { Locator, Page } from '@playwright/test'
import {
    asEnvironmentOnly,
    asNarrowed,
    expect,
    fakeReverb,
    gotoServer,
    nav,
    test,
    withInertia
} from './support/fixtures'

function explorer(page: Page): Locator {
    return page.getByRole('complementary', { name: 'Explorer' })
}

function trail(page: Page): Locator {
    return page.getByRole('navigation', { name: 'Breadcrumb' })
}

type FleetProps = {
    panel?: { live?: { streaming: boolean } }
    explorer?: { servers: unknown[] }
    crumbs?: { label: string }[]
}

const PUSHED = {
    id: 'pushed',
    name: 'pushed-01',
    href: '/dashboard',
    state: { key: 'healthy', label: 'Healthy', tone: 'success' },
    note: '0 apps'
}

async function streamFleet(page: Page, change: (props: FleetProps) => void) {
    await withInertia(page, /.*/, (body) => {
        const props = body.props as FleetProps

        if (props.panel?.live) {
            props.panel.live.streaming = true
        }

        change(props)
    })

    const reverb = await fakeReverb(page)

    return {
        async subscribed() {
            await expect.poll(() => reverb.team()).not.toBeNull()
        },
        changed() {
            reverb.emit(reverb.team() as string, 'fleet.changed')
        }
    }
}

async function failSecondFleetSubscription(page: Page) {
    await page.addInitScript(() => {
        type Subscription = { bind: (event: string, ...rest: unknown[]) => unknown }
        type PusherClass = { prototype: { subscribe: (this: unknown, name: string) => Subscription } }
        let pusher: PusherClass | undefined

        Object.defineProperty(window, 'Pusher', {
            configurable: true,
            get: () => pusher,
            set(value: PusherClass) {
                const subscribe = value.prototype.subscribe

                value.prototype.subscribe = function (this: unknown, name: string) {
                    const subscription = subscribe.call(this, name)
                    const bind = subscription.bind.bind(subscription)
                    let binds = 0

                    subscription.bind = (event, ...rest) => {
                        if (event === 'fleet.changed' && ++binds > 1) {
                            throw new Error('This subscription failed')
                        }

                        return bind(event, ...rest)
                    }

                    return subscription
                }
                pusher = value
            }
        })
    })
}

test.describe('the explorer', () => {
    test.skip(
        ({ isMobile }) => isMobile,
        'The rail and explorer start at 640px; phones use the bottom tabs and the fleet lists'
    )

    test('it lists projects with their environments, the other apps and the servers', async ({ page }) => {
        await page.goto('/dashboard')

        const atlas = explorer(page).getByTestId('explorer-project').filter({ hasText: 'Atlas' })

        await expect(atlas.getByRole('link', { name: 'Atlas', exact: true })).toBeVisible()
        await expect(atlas.getByText('Production', { exact: true })).toBeVisible()
        await expect(atlas.getByText('Staging', { exact: true })).toBeVisible()
        await expect(atlas.getByRole('link', { name: /^atlas,/ })).toBeVisible()
        await expect(atlas.getByRole('link', { name: /^atlas_production,/ })).toBeVisible()
        await expect(atlas.getByRole('link', { name: /^atlas-staging,/ })).toBeVisible()

        const others = explorer(page).getByTestId('explorer-apps')

        for (const name of ['ledger', 'relay', 'warehouse']) {
            await expect(others.getByRole('link', { name: new RegExp(`^${name},`) })).toBeVisible()
        }

        const servers = explorer(page).getByTestId('explorer-servers')

        for (const name of ['forge-01', 'forge-02', 'anvil-01', 'outpost-01']) {
            await expect(servers.getByRole('link', { name: new RegExp(`^${name},`) })).toBeVisible()
        }
    })

    test('an app opens from it and is marked as the current page', async ({ page }) => {
        await page.goto('/dashboard')

        await explorer(page)
            .getByRole('link', { name: /^ledger,/ })
            .click()

        await page.waitForURL(/\/servers\/[^/]+\/applications\/[^/]+$/)
        await expect(page.getByRole('heading', { level: 1, name: 'ledger' })).toBeVisible()
        await expect(explorer(page).getByRole('link', { name: /^ledger,/ })).toHaveAttribute('aria-current', 'page')
    })

    test('folding a project is remembered across a reload', async ({ page }) => {
        await page.goto('/dashboard')

        const fold = explorer(page).getByRole('button', { name: 'Atlas environments' })

        await expect(fold).toHaveAttribute('aria-expanded', 'true')
        await fold.click()
        await expect(fold).toHaveAttribute('aria-expanded', 'false')
        await expect(explorer(page).getByRole('link', { name: /^atlas,/ })).toBeHidden()

        await page.reload()
        await expect(explorer(page).getByRole('button', { name: 'Atlas environments' })).toHaveAttribute(
            'aria-expanded',
            'false'
        )
        await expect(explorer(page).getByRole('link', { name: /^atlas,/ })).toBeHidden()
    })

    test('hiding it is remembered, and the top bar brings it back', async ({ page }) => {
        await page.goto('/dashboard')

        await explorer(page).getByRole('button', { name: 'Hide the explorer' }).click()
        await expect(explorer(page)).toBeHidden()

        await page.reload()
        await expect(page.getByRole('heading', { level: 1 })).toBeVisible()
        await expect(explorer(page)).toBeHidden()

        await page.getByRole('button', { name: 'Show the explorer' }).click()
        await expect(explorer(page)).toBeVisible()
    })

    test('below 1280 pixels it opens over the page and closes when you go somewhere', async ({ page }) => {
        await page.setViewportSize({ width: 1024, height: 768 })
        await page.goto('/dashboard')

        await expect(page.getByRole('heading', { level: 1 })).toBeVisible()
        await expect(explorer(page)).toBeHidden()

        await page.getByRole('button', { name: 'Show the explorer' }).click()
        await expect(explorer(page)).toBeVisible()
        await explorer(page)
            .getByRole('link', { name: /^ledger,/ })
            .click()
        await page.waitForURL(/\/applications\/[^/]+$/)
        await expect(explorer(page)).toBeHidden()

        await page.getByRole('button', { name: 'Show the explorer' }).click()
        await expect(explorer(page)).toBeVisible()
        await page.keyboard.press('Escape')
        await expect(explorer(page)).toBeHidden()
    })

    test('the jump button opens the command palette', async ({ page }) => {
        await page.goto('/dashboard')

        await explorer(page).getByRole('button', { name: 'Jump to an app or server' }).click()

        const palette = page.getByRole('dialog', { name: 'Command palette' })

        await expect(palette).toBeVisible()
        await expect(palette.getByRole('combobox', { name: 'Search commands' })).toBeFocused()
    })

    test('a running deploy shows at its foot and pulses on Deploys', async ({ page }) => {
        await page.goto('/dashboard')

        const running = explorer(page).getByRole('link', { name: /^Deploying now/ })

        await expect(running).toBeVisible()
        await expect(running.getByTestId('phase-segments')).toBeVisible()
        await expect(running).toContainText(/[a-z0-9-]+ to [a-z0-9-]+/)
        await expect(nav(page).getByTestId('deploys-live')).toBeVisible()

        await running.click()
        await page.waitForURL(/\/deployments\/[^/]+$/)
    })

    test('it refreshes when the fleet changes', async ({ page }) => {
        let changed = false
        const fleet = await streamFleet(page, (props) => {
            if (changed) {
                props.explorer?.servers.push(PUSHED)
            }
        })

        await page.goto('/dashboard')
        await nav(page).getByRole('link', { name: 'Releases', exact: true }).click()
        await page.waitForURL(/\/releases|\/applications\//)
        await fleet.subscribed()
        changed = true
        fleet.changed()

        await expect(explorer(page).getByRole('link', { name: /^pushed-01,/ })).toBeVisible({ timeout: 10_000 })
    })

    test('a subscription that fails leaves the others refreshing', async ({ page }) => {
        await failSecondFleetSubscription(page)

        let changed = false
        const fleet = await streamFleet(page, (props) => {
            if (changed) {
                props.explorer?.servers.push(PUSHED)
            }
        })

        await gotoServer(page, 'forge-01')
        await fleet.subscribed()
        changed = true
        fleet.changed()

        await expect(explorer(page).getByRole('link', { name: /^pushed-01,/ })).toBeVisible({ timeout: 10_000 })
    })
})

test.describe('the breadcrumb trail', () => {
    test.skip(({ isMobile }) => isMobile, 'The trail starts at 640px; phones keep each page’s back link')

    test('it names where you are and leads back up', async ({ page }) => {
        await page.goto('/dashboard')
        await explorer(page)
            .getByRole('link', { name: /^atlas,/ })
            .click()
        await page.waitForURL(/\/applications\/[^/]+$/)

        await expect(trail(page).getByRole('listitem')).toHaveText(['Monoven Ltd', 'Atlas', 'Production', 'atlas'])
        await expect(trail(page).getByText('atlas', { exact: true })).toHaveAttribute('aria-current', 'page')

        await trail(page).getByRole('link', { name: 'Atlas', exact: true }).click()
        await page.waitForURL(/\/projects\/[^/]+$/)
        await expect(page.getByRole('heading', { level: 1, name: 'Atlas' })).toBeVisible()
    })

    test('a rename made elsewhere reaches it on the next live refresh', async ({ page }) => {
        let renamed = false
        const fleet = await streamFleet(page, (props) => {
            if (renamed && props.crumbs) {
                props.crumbs[props.crumbs.length - 1].label = 'forge-renamed'
            }
        })

        await gotoServer(page, 'forge-01')
        await expect(trail(page).getByRole('listitem').last()).toHaveText('forge-01')
        await fleet.subscribed()
        renamed = true
        fleet.changed()

        await expect(trail(page).getByRole('listitem').last()).toHaveText('forge-renamed', { timeout: 10_000 })
    })
})

test.describe('the attention status', () => {
    test.skip(({ isMobile }) => isMobile, 'The top bar starts at 640px; phones see attention on the fleet page')

    test('it lists what needs attention and leads there', async ({ page }) => {
        await page.goto('/dashboard')

        const status = page.getByTestId('fleet-status')

        await expect(status).toHaveText(/\d+ needs? attention/)
        await status.click()

        const list = page.getByRole('dialog', { name: 'Needs attention' })

        await expect(list.getByRole('link', { name: /outpost-01/ })).toBeVisible()
        await list.getByRole('link', { name: /outpost-01/ }).click()
        await page.waitForURL(/\/servers\/[^/]+(\/[a-z]+)?$/)
    })
})

test.describe('the bottom tabs', () => {
    test.skip(({ isMobile }) => !isMobile, 'Wider screens navigate with the rail; the bottom tabs are for phones')

    test('they show a running deploy on Deploys', async ({ page }) => {
        await page.goto('/dashboard')

        await expect(nav(page).getByRole('link', { name: 'Deploys', exact: true })).toBeVisible()
        await expect(nav(page).getByTestId('deploys-live')).toBeVisible()
    })
})

asNarrowed.describe('the explorer of a narrowed member', () => {
    asNarrowed.skip(({ isMobile }) => isMobile, 'The explorer starts at 640px')

    asNarrowed('it shows only what they were given', async ({ page }) => {
        await page.goto('/dashboard')

        await expect(explorer(page).getByTestId('explorer-project')).toHaveCount(0)
        await expect(
            explorer(page)
                .getByTestId('explorer-servers')
                .getByRole('link', { name: /^forge-01,/ })
        ).toBeVisible()
        await expect(
            explorer(page)
                .getByTestId('explorer-servers')
                .getByRole('link', { name: /^forge-02,/ })
        ).toHaveCount(0)
        await expect(
            explorer(page)
                .getByTestId('explorer-apps')
                .getByRole('link', { name: /^warehouse,/ })
        ).toBeVisible()
    })
})

asEnvironmentOnly.describe('the explorer of an environment-only member', () => {
    asEnvironmentOnly.skip(({ isMobile }) => isMobile, 'The explorer starts at 640px')

    asEnvironmentOnly('it shows that environment alone', async ({ page }) => {
        await page.goto('/dashboard')

        const project = explorer(page).getByTestId('explorer-project')

        await expect(project).toHaveCount(1)
        await expect(project.getByRole('link', { name: 'Storefront', exact: true })).toBeVisible()
        await expect(project.getByText('Staging', { exact: true })).toBeVisible()
        await expect(project.getByText('Production', { exact: true })).toHaveCount(0)
        await expect(explorer(page).getByTestId('explorer-servers')).toHaveCount(0)
    })
})
