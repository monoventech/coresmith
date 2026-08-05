import type { Page } from '@playwright/test'
import { asMember, expect, test } from './support/fixtures'

async function gotoSourceProviders(page: Page) {
    await page.goto('/settings/profile')
    await page.getByRole('link', { name: 'Source providers' }).click()
    await page.waitForURL(/\/settings\/source-providers$/)
}

test.describe('source providers', () => {
    test('the settings nav reaches the page', async ({ page }) => {
        await gotoSourceProviders(page)

        await expect(page.getByRole('heading', { name: 'Source providers' })).toBeVisible()
        await expect(page.getByRole('link', { name: 'Source providers' })).toHaveAttribute('aria-current', 'page')
    })

    test('a connected account is listed with its permissions and expiry', async ({ page }) => {
        await gotoSourceProviders(page)

        const entry = page.getByRole('listitem').filter({ hasText: 'GitHub — oseughu' })

        await expect(entry).toBeVisible()
        await expect(entry).toContainText('GitHub App (installation)')
        await expect(entry).toContainText('contents:read')
        await expect(entry).toContainText('Active')
        await expect(entry).toContainText(/Expires in \d+h \d+m/)
    })

    test('no token material reaches the page', async ({ page }) => {
        await gotoSourceProviders(page)

        const html = await page.content()

        expect(html).not.toContain('ghs_e2e_never_rendered')
        expect(html).not.toContain('access_token')
        expect(html).not.toContain('refresh_token')
        expect(html).not.toContain('app_private_key')
    })

    test('the page explains that tokens never clone', async ({ page }) => {
        await gotoSourceProviders(page)

        await expect(page.getByText(/never clone/i)).toBeVisible()
        await expect(page.getByText(/read-only deploy key/i)).toBeVisible()
    })

    test('the manage link opens on the provider and does not leak the referrer', async ({ page }) => {
        await gotoSourceProviders(page)

        const manage = page.getByRole('link', { name: 'Manage on GitHub' })

        await expect(manage).toHaveAttribute('href', 'https://github.com/settings/installations')
        await expect(manage).toHaveAttribute('rel', /noopener/)
        await expect(manage).toHaveAttribute('target', '_blank')
    })

    test('providers the instance has no credentials for are marked, not offered', async ({ page }) => {
        await gotoSourceProviders(page)

        const available = page.getByRole('heading', { name: 'Available providers' }).locator('..')

        await expect(available).toContainText('GitLab')
        await expect(available).toContainText('Bitbucket')
        await expect(available).toContainText('Not configured on this instance')
        await expect(page.getByRole('link', { name: 'Connect Bitbucket' })).toHaveCount(0)
    })

    test('a configured provider is offered as a connect link, not a form post', async ({ page }) => {
        await gotoSourceProviders(page)

        const connect = page.getByRole('link', { name: 'Connect GitLab' })

        await expect(connect).toBeVisible()
        await expect(connect).toHaveAttribute('href', /\/settings\/source-providers\/gitlab\/connect$/)
    })

    test('an owner is offered the disconnect control', async ({ page }) => {
        await gotoSourceProviders(page)

        await expect(page.getByRole('button', { name: 'Disconnect' })).toBeVisible()
    })

    test('the page does not scroll sideways', async ({ page }) => {
        await gotoSourceProviders(page)

        const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)

        expect(overflow).toBeLessThanOrEqual(1)
    })
})

asMember.describe('source providers as a member', () => {
    asMember('a member sees the connection but no disconnect control', async ({ page }) => {
        await gotoSourceProviders(page)

        await expect(page.getByText('GitHub — oseughu')).toBeVisible()
        await expect(page.getByRole('button', { name: 'Disconnect' })).toHaveCount(0)
        await expect(page.getByRole('link', { name: 'Manage on GitHub' })).toHaveCount(0)
        await expect(page.getByRole('link', { name: 'Connect GitLab' })).toHaveCount(0)
        await expect(page.getByText('Ready to connect')).toBeVisible()
    })
})
