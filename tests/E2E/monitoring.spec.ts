// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

import type { Page } from '@playwright/test'
import { expect, gotoApplication, gotoServer, gotoSettings, isMobile, test, toast } from './support/fixtures'

function saved(page: Page) {
    return page.waitForResponse(
        (response) => response.request().method() === 'PUT' && response.url().includes('/settings/notifications')
    )
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
        await expect(banner).toContainText(
            'The connection to port 22 was refused or timed out. Check the server is running and its firewall allows SSH from Coresmith.'
        )
        await expect(page.getByTestId('disk-warning')).toHaveCount(0)
    })

    test('a server it cannot reach over SSH offers no reboot and says why', async ({ page }) => {
        await gotoServer(page, 'down-01')

        const reboot = page.getByRole('button', { name: 'Reboot server' })
        const why = 'Reboot server needs SSH, so restart the machine yourself until Coresmith can reach it.'

        await expect(reboot).toBeDisabled()
        await expect(reboot).toHaveAccessibleDescription(why)
        await expect(page.getByTestId('unreachable-banner')).toContainText(why)
    })

    test('a server that answers shows no unreachable banner', async ({ page }) => {
        await gotoServer(page, 'hot-01')

        await expect(page.getByRole('heading', { level: 1, name: 'hot-01' })).toBeVisible()
        await expect(page.getByTestId('unreachable-banner')).toHaveCount(0)
        await expect(page.getByRole('button', { name: 'Reboot server' })).toBeEnabled()
    })

    test('the domains card says what each certificate is', async ({ page }, testInfo) => {
        test.skip(isMobile(testInfo), "The Domains card is not drawn below 640px, as the mobile prototype's app page")

        await gotoApplication(page, 'bench-01', 'certs-app')

        const badge = (domain: string) =>
            page.getByTestId('domain-row').filter({ hasText: domain }).locator('[data-slot="badge"]')

        await expect(badge('certs.monoven.io')).toHaveText('TLS')
        await expect(badge('certs.monoven.io')).toHaveAttribute('title', /^Valid until .+, checked .+$/)
        await expect(badge('soon.monoven.io')).toHaveText('Expires in 3 days')
        await expect(badge('soon.monoven.io')).toHaveAttribute(
            'title',
            'Caddy should have renewed this by now. Check soon.monoven.io still points at 198.51.100.40.'
        )
        await expect(badge('gone.monoven.io')).toHaveText('Expired')
        await expect(badge('gone.monoven.io')).toHaveAttribute(
            'title',
            'Caddy should have renewed this by now. Check gone.monoven.io still points at 198.51.100.40.'
        )
        await expect(badge('dark.monoven.io')).toHaveText('No certificate')
        await expect(badge('dark.monoven.io')).toHaveAttribute(
            'title',
            'Caddy has no certificate for this name yet. Point its DNS at 198.51.100.40 and Caddy requests one.'
        )
    })

    test('a domain not checked yet carries no badge', async ({ page }, testInfo) => {
        test.skip(isMobile(testInfo), "The Domains card is not drawn below 640px, as the mobile prototype's app page")

        await gotoApplication(page, 'bench-01', 'hungry')

        await expect(page.getByTestId('domain-row').filter({ hasText: 'hungry.bench.monoven.io' })).toBeVisible()
        await expect(page.getByTestId('domain-row').locator('[data-slot="badge"]')).toHaveCount(0)
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
        await expect(alerts).toContainText(
            'A server stops answering, runs out of memory, or a certificate is about to expire'
        )
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
