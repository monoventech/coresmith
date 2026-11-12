import type { Page } from '@playwright/test'
import { asGuest, blockingViolations, expect, openSecurity, own, PASSWORD, tabs, test, toast } from './support/fixtures'

async function gotoAccount(page: Page) {
    await page.goto('/dashboard')
    await page.getByRole('button', { name: 'Account' }).click()
    await page.getByRole('dialog').getByRole('link', { name: 'Your account' }).click()
    await page.waitForURL(/\/settings\/profile$/)
}

test.describe('your account', () => {
    test('the profile shows who you are', async ({ page }) => {
        await gotoAccount(page)

        await expect(page.getByRole('heading', { level: 1, name: 'Your account' })).toBeVisible()
        await expect(tabs(page, 'Account').getByRole('link', { name: 'Profile' })).toHaveAttribute(
            'aria-current',
            'page'
        )
        await expect(page.getByLabel('Name', { exact: true })).toHaveValue('Maya Lindqvist')
        await expect(page.getByLabel('Email address')).toHaveValue('owner@coresmith.test')
    })

    test('the name is saved', async ({ page }) => {
        await gotoAccount(page)

        await page.getByLabel('Name', { exact: true }).fill('Maya L.')
        await page.getByRole('button', { name: 'Save' }).click()

        await expect(toast(page)).toContainText('Saved.')
        await page.reload()
        await expect(page.getByLabel('Name', { exact: true })).toHaveValue('Maya L.')

        await page.getByLabel('Name', { exact: true }).fill('Maya Lindqvist')
        await page.getByRole('button', { name: 'Save' }).click()
        await expect(toast(page)).toContainText('Saved.')
    })

    test('the security tab holds the password and two-factor', async ({ page }) => {
        await gotoAccount(page)
        await expect(tabs(page, 'Account').getByRole('link', { name: 'Security' })).toHaveAttribute(
            'href',
            /\/settings\/security$/
        )

        await openSecurity(page)

        await expect(tabs(page, 'Account').getByRole('link', { name: 'Security' })).toHaveAttribute(
            'aria-current',
            'page'
        )
        await expect(page.getByTestId('password-card').getByLabel('New password')).toBeVisible()
        await expect(page.getByTestId('two-factor-card')).toContainText('Off')
        await expect(page.getByRole('button', { name: 'Enable 2FA' })).toBeVisible()
    })

    test('a wrong current password is refused next to the field', async ({ page }) => {
        await openSecurity(page)

        const card = page.getByTestId('password-card')

        await card.getByLabel('Current password').fill('not-the-password')
        await card.getByLabel('New password').fill('a-long-enough-passphrase')
        await card.getByLabel('Confirm password').fill('a-long-enough-passphrase')
        await card.getByRole('button', { name: 'Save password' }).click()

        await expect(card).toContainText(/password is incorrect/i)
    })
})

asGuest.describe('changing the password', () => {
    asGuest('the toast says the other sessions ended', async ({ page }, testInfo) => {
        await page.goto('/login')
        await page.getByLabel('Email address').fill(`${own(testInfo, 'new-password')}@coresmith.test`)
        await page.getByLabel('Password', { exact: true }).fill(PASSWORD)
        await page.getByRole('button', { name: 'Sign in' }).click()
        await page.waitForURL(/\/dashboard$/)

        await page.goto('/settings/security')
        await page.getByLabel('Password', { exact: true }).fill(PASSWORD)
        await page.getByRole('button', { name: 'Confirm' }).click()
        await page.waitForURL(/\/settings\/security$/)

        const card = page.getByTestId('password-card')

        await card.getByLabel('Current password').fill(PASSWORD)
        await card.getByLabel('New password').fill('a-long-enough-passphrase')
        await card.getByLabel('Confirm password').fill('a-long-enough-passphrase')
        await card.getByRole('button', { name: 'Save password' }).click()

        await expect(toast(page)).toContainText("Saved. You're signed out everywhere else.")
    })
})

asGuest.describe('confirming the password', () => {
    asGuest('security asks for the password again on a fresh session', async ({ page }) => {
        await page.goto('/login')
        await page.getByLabel('Email address').fill('rowan@coresmith.test')
        await page.getByLabel('Password', { exact: true }).fill(PASSWORD)
        await page.getByRole('button', { name: 'Sign in' }).click()
        await page.waitForURL(/\/dashboard$/)

        await page.goto('/settings/security')

        await expect(page.getByRole('heading', { level: 1, name: 'Confirm your password' })).toBeVisible()
        expect(await blockingViolations(page)).toEqual([])

        await page.getByLabel('Password', { exact: true }).fill(PASSWORD)
        await page.getByRole('button', { name: 'Confirm' }).click()

        await page.waitForURL(/\/settings\/security$/)
        await expect(page.getByTestId('password-card')).toBeVisible()
    })
})
