import type { Page } from '@playwright/test'
import {
    applicationRow,
    blockingViolations,
    dialog,
    expect,
    gotoServer,
    main,
    nav,
    test,
    useTheme
} from './support/fixtures'

function palette(page: Page) {
    return page.getByRole('dialog', { name: 'Command palette' })
}

function group(page: Page, name: string) {
    return palette(page).getByRole('group', { name, exact: true })
}

async function openPalette(page: Page) {
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible()
    await page.keyboard.press('ControlOrMeta+K')
    await expect(palette(page)).toBeVisible()
    await expect(palette(page).getByRole('option').first()).toBeVisible()
}

test.describe('the shell', () => {
    test('every section is reachable from the main navigation', async ({ page }) => {
        await page.goto('/dashboard')

        const sections = [
            ['Deploys', /\/deployments$/],
            ['Releases', /\/releases$/],
            ['Audit log', /\/audit-log$/],
            ['Settings', /\/settings$/],
            ['Fleet', /\/dashboard$/]
        ] as const

        await expect(nav(page).getByRole('link')).toContainText([
            'Fleet',
            'Deploys',
            'Releases',
            'Audit log',
            'Settings'
        ])

        for (const [name, url] of sections) {
            await nav(page).getByRole('link', { name, exact: true }).click()
            await page.waitForURL(url)
            await expect(nav(page).getByRole('link', { name, exact: true })).toHaveAttribute('aria-current', 'page')
        }
    })

    test('a finished setup takes its item out of the navigation', async ({ page }) => {
        await page.goto('/dashboard')

        await expect(nav(page).getByRole('link', { name: /Finish setup/ })).toHaveCount(0)
    })

    test('the page title names the page and the product', async ({ page }) => {
        await page.goto('/dashboard')

        await expect(page).toHaveTitle(/Fleet/)
    })
})

test.describe('the rail', () => {
    test.skip(({ isMobile }) => isMobile, 'The rail starts at 640px; phones navigate with the bottom tabs')

    test('it carries the Coresmith mark', async ({ page }) => {
        await page.goto('/dashboard')

        await expect(nav(page).getByRole('link', { name: 'Coresmith', exact: true })).toBeVisible()
    })

    test('it starts dark, flips the theme and keeps it across a reload', async ({ page }) => {
        await page.goto('/dashboard')

        await expect(page.locator('html')).toHaveClass(/\bdark\b/)

        await page.getByRole('button', { name: 'Light mode' }).click()
        await expect(page.locator('html')).not.toHaveClass(/\bdark\b/)

        await page.reload()
        await expect(page.locator('html')).not.toHaveClass(/\bdark\b/)

        await page.getByRole('button', { name: 'Dark mode' }).click()
        await expect(page.locator('html')).toHaveClass(/\bdark\b/)
    })
})

test.describe('the command palette', () => {
    test('it opens from the keyboard and runs a command', async ({ page }) => {
        await page.goto('/dashboard')
        await openPalette(page)

        await page.keyboard.type('Deployments')
        await expect(palette(page).getByRole('option')).toHaveCount(1)
        await page.keyboard.press('Enter')

        await page.waitForURL(/\/deployments$/)
        await expect(palette(page)).toHaveCount(0)
    })

    test('the palette offers no billing on a self-hosted panel', async ({ page }) => {
        await page.goto('/dashboard')
        await openPalette(page)

        await page.keyboard.type('Billing')

        await expect(palette(page)).toContainText('Nothing matches “Billing”.')
        await expect(palette(page).getByRole('option')).toHaveCount(0)
    })

    test('it opens on a few rows: the running deploy and the actions, never every app', async ({ page }) => {
        await page.goto('/dashboard')
        await openPalette(page)

        await expect(group(page, 'Actions').getByRole('option').first()).toHaveAccessibleName(
            /^Watch the running deploy/
        )
        await expect.poll(() => palette(page).getByRole('option').count()).toBeLessThanOrEqual(14)
        await expect(palette(page).getByRole('option', { name: /^forge-01/ })).toHaveCount(0)

        await page.keyboard.type('forge-01')
        await expect(group(page, 'Servers').getByRole('option').first()).toHaveAccessibleName(/^forge-01\b/)
    })

    test('on a page about one thing it offers that thing first, and remembers what was opened', async ({ page }) => {
        await gotoServer(page, 'forge-01')
        await openPalette(page)

        await expect(group(page, 'This server').getByRole('option', { name: /^Hardening/ })).toBeVisible()
        await expect(group(page, 'This server').getByRole('option', { name: /^Open\b/ })).toHaveCount(0)
        await page.keyboard.press('Escape')

        await gotoServer(page, 'anvil-01')
        await openPalette(page)

        await expect(group(page, 'Recent').getByRole('option', { name: /^forge-01\b/ })).toBeVisible()
        await expect(group(page, 'Recent').getByRole('option', { name: /^anvil-01\b/ })).toHaveCount(0)
    })

    test('typing ranks names that start with it first, shows five of each kind, and the rest on request', async ({
        page
    }) => {
        await page.goto('/dashboard')
        await openPalette(page)

        await page.keyboard.type('b')

        const apps = group(page, 'Apps')

        await expect(apps.getByRole('option')).toHaveCount(6)
        await expect(apps.getByRole('option').first()).toHaveAccessibleName(/^b/)
        await expect(apps.getByTestId('palette-more')).toHaveAccessibleName(/^Show all \d+ apps$/)

        await apps.getByTestId('palette-more').click()

        await expect.poll(() => apps.getByRole('option').count()).toBeGreaterThan(6)
        await expect(apps.getByTestId('palette-more')).toHaveCount(0)
        await expect.poll(() => apps.getByRole('option').count()).toBeLessThanOrEqual(50)
    })

    test("the right arrow lists a thing's pages, and the left arrow goes back to the results", async ({ page }) => {
        await page.goto('/dashboard')
        await openPalette(page)

        await page.keyboard.type('forge-01')
        await expect(group(page, 'Servers').getByRole('option').first()).toHaveAttribute('aria-selected', 'true')
        await page.keyboard.press('ArrowRight')

        const pages = group(page, 'forge-01')

        await expect(pages.getByRole('option', { name: /^Open\b/ })).toBeVisible()
        await expect(pages.getByRole('option', { name: /^Hardening/ })).toBeVisible()
        await expect(palette(page).getByRole('combobox')).toHaveValue('')

        await page.keyboard.press('ArrowLeft')

        await expect(palette(page).getByRole('combobox')).toHaveValue('forge-01')
        await expect(group(page, 'Servers').getByRole('option').first()).toHaveAttribute('aria-selected', 'true')

        await page.keyboard.press('ArrowRight')
        await page.keyboard.type('hard')
        await page.keyboard.press('Enter')

        await page.waitForURL(/\/hardening$/)
        await expect(page.getByRole('heading', { level: 1, name: 'forge-01' })).toBeVisible()
    })

    test("a pointer opens a thing's pages from the chevron at the end of its row", async ({ page }) => {
        await page.goto('/dashboard')
        await openPalette(page)

        await page.keyboard.type('atlas')

        const atlas = group(page, 'Apps').getByRole('option', { name: /^atlas production/ })

        await atlas.hover()
        await atlas.getByTestId('palette-inside').click()

        await expect(group(page, 'atlas').getByRole('option', { name: /^Releases/ })).toBeVisible()
        await group(page, 'atlas')
            .getByRole('option', { name: /^Releases/ })
            .click()

        await expect(page.getByRole('heading', { level: 1, name: /^Releases · atlas/ })).toBeVisible()
    })

    for (const theme of ['light', 'dark'] as const) {
        test(`the open palette has no serious or critical accessibility violations in ${theme}`, async ({ page }) => {
            await useTheme(page, theme)
            await gotoServer(page, 'forge-01')
            await openPalette(page)

            expect(await blockingViolations(page)).toEqual([])

            await page.keyboard.type('b')
            await expect(group(page, 'Apps')).toBeVisible()

            expect(await blockingViolations(page)).toEqual([])

            await page.keyboard.type('zzzz')
            await expect(palette(page)).toContainText('Nothing matches “bzzzz”.')

            expect(await blockingViolations(page)).toEqual([])
        })
    }

    test('the arrow keys move the selection', async ({ page }) => {
        await page.goto('/dashboard')
        await openPalette(page)

        const options = palette(page).getByRole('option')

        await expect(options.first()).toHaveAttribute('aria-selected', 'true')
        await page.keyboard.press('ArrowDown')
        await expect(options.nth(1)).toHaveAttribute('aria-selected', 'true')
        await expect(options.first()).toHaveAttribute('aria-selected', 'false')
    })

    test('a pointer resting where the list opens does not move the selection', async ({ page }) => {
        await page.goto('/dashboard')
        await openPalette(page)

        const options = palette(page).getByRole('option')

        await page.keyboard.type('b')
        await expect(options.nth(8)).toBeAttached()

        const third = await options.nth(2).boundingBox()

        await page.keyboard.press('Escape')
        await expect(palette(page)).toHaveCount(0)
        await page.mouse.move((third?.x ?? 0) + 40, (third?.y ?? 0) + (third?.height ?? 0) / 2)
        await openPalette(page)
        await page.keyboard.type('b')
        await expect(options.nth(8)).toBeAttached()
        await palette(page)
            .getByRole('listbox')
            .evaluate((list) => list.scrollBy(0, 88))
        await page.keyboard.press('ArrowDown')

        await expect(options.nth(1)).toHaveAttribute('aria-selected', 'true')
    })

    test('a search with no match says so', async ({ page }) => {
        await page.goto('/dashboard')
        await openPalette(page)

        await page.keyboard.type('zzzz-nothing')

        await expect(palette(page)).toContainText('Nothing matches')
    })

    test('escape closes it', async ({ page }) => {
        await page.goto('/dashboard')
        await openPalette(page)

        await page.keyboard.press('Escape')

        await expect(palette(page)).toHaveCount(0)
    })

    test('opening a server from it lands on the server', async ({ page }) => {
        await page.goto('/dashboard')
        await openPalette(page)

        await page.keyboard.type('outpost-01')
        await page.keyboard.press('Enter')

        await expect(page.getByRole('heading', { level: 1, name: 'outpost-01' })).toBeVisible()
    })

    test('switching the theme from it flips the page', async ({ page }) => {
        await page.goto('/dashboard')
        await expect(page.locator('html')).toHaveClass(/\bdark\b/)
        await openPalette(page)

        await page.keyboard.type('Switch theme')
        await page.keyboard.press('Enter')

        await expect(page.locator('html')).not.toHaveClass(/\bdark\b/)
    })
})

test.describe('page scrolling', () => {
    test('an in-app link opens at the top, and going back restores where the page was', async ({ page }) => {
        await gotoServer(page, 'bench-01')

        const row = applicationRow(page, 'legacy-api')
        await row.scrollIntoViewIfNeeded()
        const scrolled = await main(page).evaluate((el) => el.scrollTop)
        expect(scrolled).toBeGreaterThan(0)

        await row.click()
        await page.waitForURL(/\/applications\/[\w.-]+$/)
        await expect(page.getByRole('heading', { level: 1, name: 'legacy-api', exact: true })).toBeVisible()
        await expect(main(page)).toHaveJSProperty('scrollTop', 0)

        await page.goBack()
        await page.waitForURL(/\/servers\/[\w.-]+$/)
        await expect(main(page)).toHaveJSProperty('scrollTop', scrolled)
    })
})

test.describe('the bottom tabs', () => {
    test.skip(({ isMobile }) => !isMobile, 'The bottom tabs show below 640px; wider screens have no bar over the page')

    test('a control focused at the bottom edge scrolls clear of them', async ({ page }) => {
        await page.goto('/deployments')

        const row = page.getByTestId('deployment-row').nth(10)

        await expect(row).toBeVisible()
        await row.evaluate((el) => {
            const scroller = el.closest('main') as HTMLElement

            scroller.scrollTop += el.getBoundingClientRect().top - (window.innerHeight - 12)
        })
        await row.focus()

        const control = await row.boundingBox()
        const tabs = await nav(page).boundingBox()

        expect(control).not.toBeNull()
        expect(tabs).not.toBeNull()
        expect(control!.y + control!.height).toBeLessThanOrEqual(tabs!.y)
    })
})

test.describe('the account dialog', () => {
    test('it says who is signed in and where they belong', async ({ page }) => {
        await page.goto('/dashboard')
        await page.getByRole('button', { name: 'Account' }).click()

        await expect(dialog(page)).toContainText('Signed in as owner@coresmith.test, owner of Monoven Ltd.')
        await expect(dialog(page)).toContainText('Monoven Ltd')
        await expect(dialog(page)).toContainText('current')
    })

    test('it leads to your own account', async ({ page }) => {
        await page.goto('/dashboard')
        await page.getByRole('button', { name: 'Account' }).click()
        await dialog(page).getByRole('link', { name: 'Your account' }).click()

        await page.waitForURL(/\/settings\/profile$/)
        await expect(page.getByRole('heading', { level: 1, name: 'Your account' })).toBeVisible()
    })

    test('it leads to the settings', async ({ page }) => {
        await page.goto('/dashboard')
        await page.getByRole('button', { name: 'Account' }).click()
        await dialog(page).getByRole('link', { name: 'Settings' }).click()

        await page.waitForURL(/\/settings$/)
        await expect(page.getByRole('heading', { level: 1, name: 'Settings' })).toBeVisible()
    })
})

test.describe('the content security policy', () => {
    test('the main pages run under the policy without a single violation', async ({ page }) => {
        const problems: string[] = []

        page.on('console', (message) => {
            if (message.type() === 'error' && message.text().includes('Content Security Policy')) {
                problems.push(message.text())
            }
        })
        page.on('pageerror', (error) => problems.push(error.message))

        for (const path of ['/dashboard', '/deployments', '/releases', '/settings']) {
            const response = await page.goto(path)
            const policy = response?.headers()['content-security-policy'] ?? ''

            expect(policy).toMatch(/script-src 'self' 'nonce-[A-Za-z0-9+/=]+'/)
            await expect(page.getByRole('heading', { level: 1 })).toBeVisible()
        }

        expect(problems).toEqual([])
    })
})
