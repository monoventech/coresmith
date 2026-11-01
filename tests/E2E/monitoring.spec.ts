// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

import type { Page } from '@playwright/test'
import { expect, gotoServer, gotoSettings, isMobile, test, toast } from './support/fixtures'

function saved(page: Page) {
    return page.waitForResponse((response) => response.request().method() === 'PUT' && response.url().includes('/settings/notifications'))
}

function card(page: Page, name: string) {
    return page.getByTestId('server-card').filter({ has: page.getByRole('link', { name, exact: true }) })
}

test.describe('monitoring', () => {
    test('an unreachable server says so on the fleet and counts as needing attention', async ({ page }, testInfo) => {
        await page.goto('/dashboard')

        await expect(card(page, 'down-01')).toContainText('Unreachable')

        if (!isMobile(testInfo)) {
            const attention = page.getByTestId('tile-attention')

            await expect(attention).toContainText('Needs attention')
            await expect(attention).not.toContainText('all clear')
        }
    })

    test('the server page says it cannot reach the server and what to check', async ({ page }) => {
        await gotoServer(page, 'down-01')

        const banner = page.getByTestId('unreachable-banner')

        await expect(banner).toContainText("Coresmith can't reach down-01 over SSH.")
        await expect(banner).toContainText('The last answer was ')
        await expect(banner).toContainText('The connection to port 22 was refused or timed out. Check the server is running and its firewall allows SSH from this panel.')
        await expect(page.getByTestId('disk-warning')).toHaveCount(0)
    })

    test('a server that answers shows no unreachable banner', async ({ page }) => {
        await gotoServer(page, 'hot-01')

        await expect(page.getByRole('heading', { level: 1, name: 'hot-01' })).toBeVisible()
        await expect(page.getByTestId('unreachable-banner')).toHaveCount(0)
    })

    test('sustained memory shows as a warning', async ({ page }) => {
        await page.goto('/dashboard')

        await expect(card(page, 'hot-01')).toContainText('Memory 98%')
    })

    test("the notifications tab lists six rows in the prototype's style", async ({ page }) => {
        await gotoSettings(page, 'Notifications')

        const rows = page.getByTestId('notifications-card').locator('[data-testid^="notification-"]')

        await expect(rows).toHaveCount(6)
        expect(await rows.evaluateAll((items) => items.map((item) => item.getAttribute('data-testid')))).toEqual([
            'notification-deploy_failed',
            'notification-disk_pressure',
            'notification-server_alerts',
            'notification-provisioning',
            'notification-toggle_changes',
            'notification-weekly_digest'
        ])

        const alerts = page.getByTestId('notification-server_alerts')

        await expect(alerts).toContainText('Server problems')
        await expect(alerts).toContainText('A server stops answering, runs out of memory, or a certificate is about to expire')
        await expect(alerts.getByRole('switch')).toBeChecked()

        const provisioning = page.getByTestId('notification-provisioning').getByRole('switch')

        await expect(provisioning).toBeChecked()
        await Promise.all([saved(page), provisioning.click()])
        await expect(toast(page)).toContainText('Saved.')
        await expect(provisioning).not.toBeChecked()

        await Promise.all([saved(page), provisioning.click()])
        await expect(provisioning).toBeChecked()

        await page.reload()
        await expect(page.getByTestId('notification-provisioning').getByRole('switch')).toBeChecked()
    })
})
