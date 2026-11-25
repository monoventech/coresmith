import type { Locator, Page } from '@playwright/test'
import {
    asNarrowed,
    asPartlyShared,
    blockingViolations,
    expect,
    gotoServer,
    isMobile,
    nav,
    streamFleet,
    test,
    useTheme
} from './support/fixtures'

function explorer(page: Page): Locator {
    return page.getByRole('complementary', { name: 'Explorer' })
}

function trail(page: Page): Locator {
    return page.getByRole('navigation', { name: 'Breadcrumb' })
}

const PUSHED = {
    id: 'pushed',
    name: 'pushed-01',
    href: '/dashboard',
    state: { key: 'healthy', label: 'Healthy', tone: 'success' },
    note: '0 apps'
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

    test('it lists projects with their apps and tags, the other apps and the servers', async ({ page }) => {
        await page.goto('/dashboard')

        const atlas = explorer(page).getByTestId('explorer-project').filter({ hasText: 'Atlas' })

        await expect(atlas.getByRole('link', { name: 'Atlas', exact: true })).toBeVisible()
        await expect(atlas.getByRole('link', { name: /^atlas, production,/ })).toBeVisible()
        await expect(atlas.getByRole('link', { name: /^atlas-staging, staging,/ })).toBeVisible()
        await expect(atlas.getByRole('link', { name: /^atlas_production,/ })).toBeVisible()
        await expect(atlas.getByRole('link', { name: /^atlas, production,/ }).getByTestId('app-tags')).toHaveText(
            'production'
        )

        const others = explorer(page).getByTestId('explorer-apps')

        for (const name of ['ledger', 'relay', 'warehouse']) {
            await expect(others.getByRole('link', { name: new RegExp(`^${name},`) })).toBeVisible()
        }

        const servers = explorer(page).getByTestId('explorer-servers')

        for (const name of ['forge-01', 'forge-02', 'anvil-01', 'outpost-01']) {
            await expect(servers.getByRole('link', { name: new RegExp(`^${name},`) })).toBeVisible()
        }
    })

    test('app rows show their tags and never the server, which the tooltip names, and sections fold', async ({
        page
    }) => {
        await page.goto('/dashboard')

        const others = explorer(page).getByTestId('explorer-apps')
        const ledger = others.getByRole('link', { name: /^ledger,/ })

        await expect(ledger).not.toContainText('forge-01')
        await expect(ledger).toHaveAttribute('title', /on forge-01$/)

        await others.getByRole('button', { name: 'Apps' }).click()

        await expect(others.getByRole('button', { name: 'Apps' })).toHaveAttribute('aria-expanded', 'false')
        await expect(ledger).toHaveCount(0)

        await page.reload()
        await expect(
            explorer(page)
                .getByTestId('explorer-apps')
                .getByRole('link', { name: /^ledger,/ })
        ).toHaveCount(0)

        await explorer(page).getByTestId('explorer-apps').getByRole('button', { name: 'Apps' }).click()
        await expect(
            explorer(page)
                .getByTestId('explorer-apps')
                .getByRole('link', { name: /^ledger,/ })
        ).toBeVisible()
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

        const fold = explorer(page).getByRole('button', { name: "What's in Atlas" })

        await expect(fold).toHaveAttribute('aria-expanded', 'true')
        await fold.click()
        await expect(fold).toHaveAttribute('aria-expanded', 'false')
        await expect(explorer(page).getByRole('link', { name: /^atlas,/ })).toBeHidden()

        await page.reload()
        await expect(explorer(page).getByRole('button', { name: "What's in Atlas" })).toHaveAttribute(
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

    test('on a touch screen every control in the explorer is at least 44 pixels tall', async ({
        browser
    }, testInfo) => {
        const context = await browser.newContext({
            storageState: 'tests/E2E/.auth/user.json',
            baseURL: testInfo.project.use.baseURL,
            viewport: { width: 1024, height: 768 },
            hasTouch: true,
            isMobile: true
        })
        const page = await context.newPage()

        await page.goto('/dashboard')
        await page.getByRole('button', { name: 'Show the explorer' }).click()
        await expect(explorer(page)).toBeVisible()

        const heights = await explorer(page)
            .locator('a, button')
            .evaluateAll((controls) =>
                controls
                    .filter((control) => control.getClientRects().length > 0)
                    .map((control) => Math.round(control.getBoundingClientRect().height))
            )

        expect(heights.length).toBeGreaterThan(10)
        expect(Math.min(...heights)).toBeGreaterThanOrEqual(44)
        await context.close()
    })

    test('with a mouse the explorer keeps its compact rows', async ({ page }, testInfo) => {
        test.skip(
            isMobile(testInfo),
            'Phones show the bottom tabs instead of the explorer, as the phone prototype does'
        )

        await page.goto('/dashboard')

        const row = explorer(page).getByRole('link', { name: /^ledger,/ })

        await expect(row).toBeVisible()
        expect(Math.round((await row.boundingBox())?.height ?? 0)).toBe(30)
    })

    test('the search button beside the filter opens the command palette', async ({ page }) => {
        await page.goto('/dashboard')

        await explorer(page).getByRole('button', { name: 'Search everything' }).click()

        const palette = page.getByRole('dialog', { name: 'Command palette' })

        await expect(palette).toBeVisible()
        await expect(palette.getByRole('combobox', { name: 'Search commands' })).toBeFocused()
    })

    test('typing narrows the explorer to the names that match, and Enter opens the first', async ({ page }) => {
        await page.goto('/dashboard')

        const filter = explorer(page).getByRole('searchbox', { name: 'Filter apps and servers' })
        const results = explorer(page).getByTestId('explorer-results')

        await filter.fill('ledg')

        await expect(explorer(page).getByTestId('explorer-lists')).toBeHidden()
        await expect(results).toContainText('1 match. Enter opens it.')
        await expect(results.getByRole('link')).toHaveCount(1)
        await expect(results.getByRole('link')).toContainText('ledger')
        await expect(results.getByRole('link')).toContainText('app · forge-01')
        await expect(results.locator('mark')).toHaveText('ledg')

        await filter.press('Enter')

        await expect(page.getByRole('heading', { level: 1, name: 'ledger' })).toBeVisible()
        await expect(results.getByRole('link')).toHaveAttribute('aria-current', 'page')
    })

    test('the arrows move through the matches, and Escape goes back to the filter and clears it', async ({ page }) => {
        await page.goto('/dashboard')

        const filter = explorer(page).getByRole('searchbox', { name: 'Filter apps and servers' })
        const matches = explorer(page).getByTestId('explorer-results').getByRole('link')

        await filter.fill('forge-0')
        await expect(explorer(page).getByTestId('explorer-results')).toContainText(
            '2 matches. Enter opens the first, ↓ moves down.'
        )
        await expect(matches).toHaveCount(2)

        await filter.press('ArrowDown')
        await expect(matches.nth(0)).toBeFocused()
        await page.keyboard.press('ArrowDown')
        await expect(matches.nth(1)).toBeFocused()
        await page.keyboard.press('ArrowUp')
        await expect(matches.nth(0)).toBeFocused()

        await page.keyboard.press('Escape')
        await expect(filter).toBeFocused()
        await page.keyboard.press('Escape')
        await expect(filter).toHaveValue('')
        await expect(explorer(page).getByTestId('explorer-lists')).toBeVisible()
    })

    test('a filter that matches nothing says so and offers the search', async ({ page }) => {
        await page.goto('/dashboard')

        await explorer(page).getByRole('searchbox', { name: 'Filter apps and servers' }).fill('nothing-is-called-this')

        const results = explorer(page).getByTestId('explorer-results')

        await expect(results).toContainText('Nothing matches “nothing-is-called-this”.')
        await expect(results.getByRole('link')).toHaveCount(0)
        await results.getByRole('button', { name: 'Search everything' }).click()
        await expect(page.getByRole('dialog', { name: 'Command palette' })).toBeVisible()
    })

    test('an app pinned from its page sits at the top of the explorer until it is unpinned', async ({ page }) => {
        await page.goto('/dashboard')
        await expect(explorer(page).getByTestId('explorer-pinned')).toHaveCount(0)

        await explorer(page)
            .getByTestId('explorer-apps')
            .getByRole('link', { name: /^ledger,/ })
            .click()
        await expect(page.getByRole('heading', { level: 1, name: 'ledger' })).toBeVisible()

        const pin = page.getByRole('button', { name: 'Pin to the explorer' })

        await expect(pin).toHaveAttribute('aria-pressed', 'false')
        await pin.click()
        await expect(pin).toHaveAttribute('aria-pressed', 'true')

        const pinned = explorer(page).getByTestId('explorer-pinned')

        await expect(pinned.getByRole('link', { name: /^ledger,/ })).toHaveAttribute('aria-current', 'page')
        await expect(pinned.getByTestId('explorer-count')).toHaveText('1')

        await page.reload()
        await expect(
            explorer(page)
                .getByTestId('explorer-pinned')
                .getByRole('link', { name: /^ledger,/ })
        ).toBeVisible()

        await page.getByRole('button', { name: 'Pin to the explorer' }).click()
        await expect(explorer(page).getByTestId('explorer-pinned')).toHaveCount(0)
    })

    test('rows that are not live say so in a word, and each section counts its rows', async ({ page }) => {
        await page.goto('/dashboard')

        const staging = explorer(page)
            .getByTestId('explorer-project')
            .filter({ hasText: 'Atlas' })
            .getByRole('link', { name: /^atlas-staging, staging,/ })

        await expect(staging).toContainText('deploying')
        await expect(staging.getByTestId('app-tags')).toHaveCount(0)
        await expect(
            explorer(page)
                .getByTestId('explorer-apps')
                .getByRole('link', { name: /^busy-blog,/ })
        ).toContainText('sleeping')

        for (const section of ['explorer-apps', 'explorer-servers']) {
            const group = explorer(page).getByTestId(section)
            const rows = await group.getByRole('link', { name: /, / }).count()

            await expect(group.getByTestId('explorer-count')).toHaveText(String(rows))
        }
    })

    test('what needs attention leads its own section, five at a time', async ({ page }) => {
        await page.goto('/dashboard')

        const attention = explorer(page).getByTestId('explorer-attention')
        const total = Number(await attention.getByTestId('explorer-count').textContent())

        expect(total).toBeGreaterThan(5)
        await expect(attention.getByRole('button', { name: /^Needs attention/ })).toBeVisible()
        await expect(attention.getByRole('link', { name: /^outpost-01, / })).toBeVisible()
        await expect(attention.getByRole('link')).toHaveCount(5)

        await attention.getByRole('button', { name: `Show ${total - 5} more` }).click()
        await expect(attention.getByRole('link')).toHaveCount(total)
        await expect(attention.getByRole('button', { name: 'Show fewer' })).toHaveAttribute('aria-expanded', 'true')
    })

    for (const theme of ['light', 'dark'] as const) {
        test(`the explorer with a filter typed and an app pinned is accessible in ${theme}`, async ({ page }) => {
            await useTheme(page, theme)
            await page.goto('/dashboard')
            await explorer(page)
                .getByTestId('explorer-apps')
                .getByRole('link', { name: /^ledger,/ })
                .click()
            await page.getByRole('button', { name: 'Pin to the explorer' }).click()
            await expect(explorer(page).getByTestId('explorer-pinned')).toBeVisible()

            expect(await blockingViolations(page)).toEqual([])

            await explorer(page).getByRole('searchbox', { name: 'Filter apps and servers' }).fill('a')
            await expect(explorer(page).getByTestId('explorer-results').getByRole('link').first()).toBeVisible()

            expect(await blockingViolations(page)).toEqual([])
        })
    }

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

        await expect(trail(page).getByRole('listitem')).toHaveText(['Monoven Ltd', 'Atlas', 'atlas'])
        await expect(trail(page).getByText('atlas', { exact: true })).toHaveAttribute('aria-current', 'page')

        await trail(page).getByRole('link', { name: 'Atlas', exact: true }).click()
        await page.waitForURL(/\/projects\/[^/]+$/)
        await expect(page.getByRole('heading', { level: 1, name: 'Atlas' })).toBeVisible()
    })

    test('the arrows beside a crumb switch to another app, then another server', async ({ page }) => {
        await page.goto('/dashboard')
        await explorer(page)
            .getByRole('link', { name: /^atlas,/ })
            .click()
        await page.waitForURL(/\/applications\/[^/]+$/)

        await trail(page).getByRole('button', { name: 'Switch to another app' }).click()

        const panel = page.getByTestId('crumb-switcher-panel')

        await expect(panel.getByRole('option', { name: /^atlas\s*production/ })).toHaveAttribute('data-checked', 'true')
        await page.keyboard.type('ledger')
        await page.keyboard.press('Enter')
        await expect(page.getByRole('heading', { level: 1, name: 'ledger' })).toBeVisible()
        await expect(trail(page).getByRole('listitem')).toHaveText(['Monoven Ltd', 'Fleet', 'forge-01', 'ledger'])

        await trail(page).getByRole('button', { name: 'Switch to another server' }).click()
        await page.keyboard.type('forge-02')
        await page.keyboard.press('Enter')
        await expect(page.getByRole('heading', { level: 1, name: 'forge-02' })).toBeVisible()
    })

    test('the arrows beside a project crumb switch projects', async ({ page }) => {
        await page.goto('/projects')
        await page.getByTestId('project-card').getByRole('link', { name: 'Atlas', exact: true }).click()
        await expect(page.getByRole('heading', { level: 1, name: 'Atlas' })).toBeVisible()

        await trail(page).getByRole('button', { name: 'Switch to another project' }).click()
        await page.keyboard.type('Storefront')
        await page.keyboard.press('Enter')
        await expect(page.getByRole('heading', { level: 1, name: 'Storefront' })).toBeVisible()
    })

    for (const theme of ['light', 'dark'] as const) {
        test(`an open crumb switcher is accessible in ${theme}`, async ({ page }) => {
            await useTheme(page, theme)
            await gotoServer(page, 'forge-01')
            await trail(page).getByRole('button', { name: 'Switch to another server' }).click()
            await expect(page.getByTestId('crumb-switcher-panel')).toBeVisible()

            expect(await blockingViolations(page)).toEqual([])
        })
    }

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

asPartlyShared.describe('the explorer of a member a project is shared with in part', () => {
    asPartlyShared.skip(({ isMobile }) => isMobile, 'The explorer starts at 640px')

    asPartlyShared('it shows the project with only the app shared from it', async ({ page }) => {
        await page.goto('/dashboard')

        const project = explorer(page).getByTestId('explorer-project')

        await expect(project).toHaveCount(1)
        await expect(project.getByRole('link', { name: 'Storefront', exact: true })).toBeVisible()
        await expect(project.getByRole('link', { name: /^storefront-staging, staging,/ })).toBeVisible()
        await expect(project.getByRole('link', { name: /^storefront, production,/ })).toHaveCount(0)
        await expect(explorer(page).getByTestId('explorer-servers')).toHaveCount(0)
    })
})
