import { test as setup } from '@playwright/test'
import { signIn } from './support/fixtures'

setup('authenticate as the cloud owner', async ({ page }) => {
    await signIn(page, 'owner@cloud.test', 'tests/E2E/.auth/cloud-owner.json')
})

setup('authenticate as the cloud member', async ({ browser }) => {
    const context = await browser.newContext()
    const page = await context.newPage()

    await signIn(page, 'member@cloud.test', 'tests/E2E/.auth/cloud-member.json')

    await context.close()
})
