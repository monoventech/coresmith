import { readFileSync } from 'node:fs'
import { expect, test } from '@playwright/test'

function setupToken(): string {
    return readFileSync('storage/app/e2e-setup-token', 'utf8').trim()
}

test.describe.configure({ mode: 'serial' })

test.describe('first run', () => {
    test('an instance with no account shows the holding page, not the app', async ({ page }) => {
        const response = await page.goto('/dashboard')

        expect(response?.status()).toBe(503)
        await expect(page.getByRole('heading', { name: 'Installed, but not yet configured' })).toBeVisible()
        await expect(page.getByText(/one-time token/)).toBeVisible()
    })

    test('the setup page is refused without the token', async ({ page }) => {
        const response = await page.goto('/install')

        expect(response?.status()).toBe(403)
    })

    test('the setup page is refused with a wrong token', async ({ page }) => {
        const response = await page.goto('/install?token=not-the-real-token')

        expect(response?.status()).toBe(403)
    })

    test('the setup page opens with the token from the installer', async ({ page }) => {
        await page.goto(`/install?token=${setupToken()}`)

        await expect(page.getByRole('heading', { name: 'Create the owner account' })).toBeVisible()
        await expect(page.getByTestId('install-intro')).toContainText('the setup link stops working for good')
        await expect(page.getByLabel('Instance name')).toHaveValue('Coresmith')
    })

    test('a short password is refused and no account is created', async ({ page }) => {
        await page.goto(`/install?token=${setupToken()}`)

        await page.getByLabel('Your name').fill('Ose')
        await page.getByLabel('Email').first().fill('ose@example.com')
        await page.getByLabel('Password', { exact: true }).fill('tooshort')
        await page.getByLabel('Confirm password').fill('tooshort')
        await page.getByRole('button', { name: /Create account/ }).click()

        await expect(page.getByText(/at least 12 characters/i)).toBeVisible()
        await expect(page).toHaveURL(/\/install/)
    })

    test('the owner account is created and the setup link then stops working', async ({ page }) => {
        const token = setupToken()

        await page.goto(`/install?token=${token}`)

        await page.getByLabel('Your name').fill('Ose')
        await page.getByLabel('Email').first().fill('ose@example.com')
        await page.getByLabel('Password', { exact: true }).fill('a-long-enough-passphrase')
        await page.getByLabel('Confirm password').fill('a-long-enough-passphrase')
        await page.getByLabel('Instance name').fill('Acme Ops')
        await page.getByRole('button', { name: /Create account/ }).click()

        await page.waitForURL(/\/dashboard$/)

        await expect(page.getByText('Acme Ops').first()).toBeVisible()

        await page.goto(`/install?token=${token}`)
        await expect(page).toHaveURL(/\/dashboard$/)
    })
})
