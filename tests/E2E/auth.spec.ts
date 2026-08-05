import { asGuest, expect, settled } from './support/fixtures'

const OWNER = 'owner@coresmith.test'
const PASSWORD = 'password-for-e2e'

asGuest.describe('Authentication', () => {
    asGuest('a guest visiting a protected page is sent to sign in', async ({ page }) => {
        await page.goto('/servers')
        await expect(page).toHaveURL(/login/)
    })

    asGuest('the sign in page presents the brand and the form', async ({ page }) => {
        await page.goto('/login')

        await expect(page.getByRole('heading', { name: 'Sign in' })).toBeVisible()
        await expect(page.getByLabel('Email address')).toBeVisible()
        await expect(page.getByLabel('Password', { exact: true })).toBeVisible()
    })

    asGuest('a wrong password is refused without revealing which field was wrong', async ({ page }) => {
        await page.goto('/login')
        await page.getByLabel('Email address').fill(OWNER)
        await page.getByLabel('Password', { exact: true }).fill('not-the-password')
        await page.getByRole('button', { name: 'Sign in' }).click()

        await settled(page, 'credentials do not match')
        await expect(page).toHaveURL(/login/)
    })

    asGuest('an unknown account is refused with the same message', async ({ page }) => {
        await page.goto('/login')
        await page.getByLabel('Email address').fill('nobody@coresmith.test')
        await page.getByLabel('Password', { exact: true }).fill(PASSWORD)
        await page.getByRole('button', { name: 'Sign in' }).click()

        await settled(page, 'credentials do not match')
    })

    asGuest('a valid sign in reaches the dashboard', async ({ page }) => {
        await page.goto('/login')
        await page.getByLabel('Email address').fill(OWNER)
        await page.getByLabel('Password', { exact: true }).fill(PASSWORD)
        await page.getByRole('button', { name: 'Sign in' }).click()

        await page.waitForURL('**/dashboard')
        await expect(page.getByText(/Welcome back/i)).toBeVisible()
    })

    asGuest('the forgot password page is reachable from sign in', async ({ page }) => {
        await page.goto('/login')
        await page.getByRole('link', { name: /Forgot password/i }).click()

        await expect(page.getByRole('button', { name: /Send reset link/i })).toBeVisible()
    })

    asGuest('a reset link can be requested', async ({ page }) => {
        await page.goto('/forgot-password')
        await page.getByLabel('Email address').fill(OWNER)
        await page.getByRole('button', { name: /Send reset link/i }).click()

        await settled(page, 'reset link')
    })
})
