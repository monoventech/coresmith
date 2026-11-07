import { asGuest, cloudInvitation, dialog, expect, own, PASSWORD, test, toast } from '../support/fixtures'

test.describe('inviting someone to a cloud team', () => {
    test('the invitation is emailed and never offers a link to copy', async ({ page }, testInfo) => {
        const email = `${own(testInfo, 'invited')}@cloud.test`

        await page.goto('/monoven/settings/team')
        await page.getByRole('button', { name: 'Invite someone' }).click()
        await expect(dialog(page)).toContainText('They get an email with a link to join.')
        await dialog(page).getByLabel('Email address').fill(email)
        await dialog(page).getByRole('button', { name: 'Add to the team' }).click()

        await expect(toast(page)).toContainText(`Invitation sent to ${email}.`)

        const row = page.getByTestId('invitation-row').filter({ hasText: email })

        await expect(row).toContainText('Deployer')
        await expect(row.getByRole('button', { name: /^Copy link/ })).toHaveCount(0)
        await expect(row.getByTestId('invitation-link')).toHaveCount(0)
    })
})

asGuest.describe('joining a cloud team from an emailed invitation', () => {
    asGuest('an invited person joins the team and gets no trial team of their own', async ({ page }, testInfo) => {
        await page.goto(`/invitations/${cloudInvitation(testInfo)}`)

        await expect(page.getByRole('heading', { level: 1 })).toHaveText('Join Monoven Ltd')
        await expect(page.getByLabel('Email address')).toHaveValue(`${own(testInfo, 'joiner')}@cloud.test`)
        await expect(page.getByTestId('trust-note')).toHaveCount(0)

        await page.getByLabel('Name').fill(`Joiner ${own(testInfo, 'joiner').split('-')[1]}`)
        await page.getByLabel('Password', { exact: true }).fill(PASSWORD)
        await page.getByLabel('Confirm password').fill(PASSWORD)
        await page.getByRole('button', { name: 'Create account and join' }).click()

        await page.waitForURL(/\/monoven\/dashboard$/)
        await expect(page.getByRole('heading', { level: 1, name: 'Your fleet' })).toBeVisible()

        await page.getByRole('button', { name: 'Account' }).click()

        const switcher = dialog(page).getByTestId('team-switcher')

        await expect(switcher).toContainText('Monoven Ltd')
        await expect(switcher.getByRole('button', { name: /^Switch to / })).toHaveCount(0)
        await expect(switcher).not.toContainText("'s team")
    })
})
