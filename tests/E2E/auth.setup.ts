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

setup('authenticate as an admin who sees only what is shared', async ({ browser }) => {
    const context = await browser.newContext()
    const page = await context.newPage()

    await signIn(page, 'narrowed@coresmith.test', 'tests/E2E/.auth/narrowed.json')

    await context.close()
})

for (const code of ['chr', 'ffx', 'wbk', 'mch', 'msf']) {
    setup(`authenticate as the owner who moves a team in on ${code}`, async ({ browser }) => {
        const context = await browser.newContext()
        const page = await context.newPage()

        await signIn(page, `mover-${code}@coresmith.test`, `tests/E2E/.auth/mover-${code}.json`)

        await context.close()
    })
}
