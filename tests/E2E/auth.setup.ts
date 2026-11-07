import { expect, test as setup } from '@playwright/test'
import { signIn } from './support/fixtures'

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
