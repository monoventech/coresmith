import type { Page } from '@playwright/test'
import { asNarrowed, dialog, expect, gotoSettings, own, test, toast } from './support/fixtures'

function member(page: Page, email: string) {
    return page.getByTestId('member-row').filter({ hasText: email })
}

test.describe('what each member sees', () => {
    test('an owner narrows a member, ticks a server and an app, and the row says so', async ({ page }, testInfo) => {
        const email = `${own(testInfo, 'narrow')}@coresmith.test`
        const name = `Narrow ${own(testInfo, 'narrow').split('-')[1]}`

        await gotoSettings(page, 'Team')
        await expect(member(page, email).getByTestId('member-access')).toHaveText('Sees everything')

        await member(page, email)
            .getByRole('button', { name: `Access for ${name}` })
            .click()
        await expect(dialog(page)).toContainText(`What ${name} can see`)

        const everything = dialog(page).getByRole('switch', { name: 'Everything in Monoven Ltd' })

        await expect(everything).toBeChecked()
        await everything.click()
        await expect(dialog(page)).toContainText(
            `${name} sees only the servers and apps you tick. A server brings every app on it.`
        )

        await dialog(page).getByRole('checkbox', { name: 'forge-02', exact: true }).click()
        await dialog(page)
            .getByRole('button', { name: /apps? on forge-02$/ })
            .click()
        await expect(dialog(page).getByRole('checkbox', { name: 'atlas-staging', exact: true })).toBeChecked()
        await expect(dialog(page).getByRole('checkbox', { name: 'atlas-staging', exact: true })).toBeDisabled()
        await dialog(page)
            .getByRole('button', { name: /apps? on anvil-01$/ })
            .click()
        await dialog(page).getByRole('checkbox', { name: 'warehouse', exact: true }).click()
        await dialog(page).getByRole('button', { name: 'Save' }).click()

        await expect(toast(page)).toContainText('Saved.')
        await expect(dialog(page)).toHaveCount(0)
        await expect(member(page, email).getByTestId('member-access')).toHaveText('1 server, 1 app')

        await page.reload()
        await expect(member(page, email).getByTestId('member-access')).toHaveText('1 server, 1 app')
    })

    test('an owner invites someone to only what they share', async ({ page }, testInfo) => {
        const email = `${own(testInfo, 'shared-invite')}@coresmith.test`

        await gotoSettings(page, 'Team')
        await page.getByRole('button', { name: 'Invite someone' }).click()
        await expect(dialog(page)).toContainText('You can change this any time from their row.')

        await dialog(page).getByLabel('Email address').fill(email)
        await dialog(page).getByLabel('Access').selectOption('shared')
        await dialog(page).getByRole('button', { name: 'Add to the team' }).click()

        await expect(toast(page)).toContainText(`Invitation created.`)
        await expect(dialog(page)).toHaveCount(0)
    })
})

asNarrowed.describe('an admin', () => {
    asNarrowed("sees nobody's access and offers no access choice when inviting", async ({ page }) => {
        await gotoSettings(page, 'Team')

        await expect(member(page, 'member@coresmith.test')).toBeVisible()
        await expect(page.getByRole('button', { name: /^Access for / })).toHaveCount(0)
        await expect(page.getByTestId('member-access')).toHaveCount(0)

        await page.getByRole('button', { name: 'Invite someone' }).click()
        await expect(dialog(page).getByLabel('Role')).toBeVisible()
        await expect(dialog(page).getByLabel('Access')).toHaveCount(0)
    })
})
