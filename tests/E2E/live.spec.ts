// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

import type { Page, Route } from '@playwright/test'
import { dialog, expect, gotoApplication, gotoServer, isMobile, own, test } from './support/fixtures'

function card(page: Page, name: string) {
    return page.getByTestId('server-card').filter({ has: page.getByRole('link', { name, exact: true }) })
}

function partialReloads(page: Page): string[] {
    const seen: string[] = []

    page.on('request', (request) => {
        if (request.headers()['x-inertia-partial-data'] !== undefined) {
            seen.push(request.url())
        }
    })

    return seen
}

test.describe('live pages', () => {
    test('the fleet shows a change made elsewhere within a minute', async ({ page, browser }, testInfo) => {
        const name = own(testInfo, 'live')
        const renamed = own(testInfo, 'live-renamed')

        await page.clock.install()
        await page.goto('/dashboard')
        await expect(card(page, name)).toBeVisible()

        const elsewhere = await browser.newContext({ storageState: 'tests/E2E/.auth/user.json', baseURL: testInfo.project.use.baseURL })
        const other = await elsewhere.newPage()

        await gotoServer(other, name)
        await other.getByRole('button', { name: 'Rename' }).click()
        await other.getByRole('textbox', { name: 'Server name' }).fill(renamed)
        await other.getByRole('button', { name: 'Save' }).click()
        await expect(other.getByRole('heading', { level: 1, name: renamed })).toBeVisible()
        await elsewhere.close()

        await expect(card(page, name)).toBeVisible()

        await page.clock.runFor(61_000)

        await expect(card(page, renamed)).toBeVisible()
        await expect(card(page, name)).toHaveCount(0)
    })

    test('an open dialog holds the refresh until it closes', async ({ page }, testInfo) => {
        test.skip(isMobile(testInfo), 'The phone fleet has no quick actions, as the mobile prototype')

        await page.clock.install()
        await page.goto('/dashboard')

        const seen = partialReloads(page)

        await card(page, 'forge-01').getByRole('button', { name: 'Deploy' }).click()
        await expect(dialog(page)).toBeVisible()

        await page.clock.runFor(61_000)

        expect(seen).toHaveLength(0)
        await expect(dialog(page)).toBeVisible()

        await dialog(page).getByRole('button', { name: 'Cancel' }).click()
        await expect(dialog(page)).toHaveCount(0)

        await page.clock.runFor(61_000)

        await expect.poll(() => seen.length).toBe(1)
    })

    test("a busy toggle holds the app page's refresh", async ({ page }, testInfo) => {
        await page.clock.install()
        await gotoApplication(page, 'bench-01', own(testInfo, 'busy'))

        const seen = partialReloads(page)
        const scheduler = page.getByTestId('smart-toggles').getByRole('switch', { name: 'Scheduler' })
        const held: Route[] = []

        await page.route('**/toggles/**', (route) => {
            held.push(route)
        })
        await scheduler.click()
        await expect(scheduler).toBeDisabled()

        await page.clock.runFor(61_000)

        expect(seen).toHaveLength(0)
        await expect(scheduler).toBeDisabled()

        await Promise.all(held.map((route) => route.abort()))
    })
})
