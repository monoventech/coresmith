import { expect,  test as setup } from '@playwright/test'
import type {Page} from '@playwright/test';

const PASSWORD = 'password-for-e2e'

async function signIn(page: Page, email: string, statePath: string) {
    await page.goto('/login')
    await page.getByLabel('Email address').fill(email)
    await page.getByLabel('Password', { exact: true }).fill(PASSWORD)
    await page.getByRole('button', { name: 'Sign in' }).click()

    await page.waitForURL('**/dashboard')
    await page.context().storageState({ path: statePath })
}

setup('authenticate as owner', async ({ page }) => {
    await signIn(page, 'owner@coresmith.test', 'tests/E2E/.auth/user.json')
    await expect(page).toHaveURL(/dashboard/)
})

setup('authenticate as member', async ({ browser }) => {
    const context = await browser.newContext()
    const page = await context.newPage()

    await signIn(page, 'member@coresmith.test', 'tests/E2E/.auth/member.json')

    await context.close()
})
