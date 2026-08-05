import { expect, test as setup } from '@playwright/test'

const OWNER_EMAIL = 'owner@coresmith.test'
const PASSWORD = 'password-for-e2e'

setup('authenticate', async ({ page }) => {
    await page.goto('/login')

    await page.getByLabel('Email address').fill(OWNER_EMAIL)
    await page.getByLabel('Password', { exact: true }).fill(PASSWORD)
    await page.getByRole('button', { name: 'Sign in' }).click()

    await page.waitForURL('**/dashboard')
    await expect(page.getByRole('link', { name: /Dashboard/i }).first()).toBeVisible()

    await page.context().storageState({ path: 'tests/E2E/.auth/user.json' })
})
