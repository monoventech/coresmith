import {
    captureClipboard,
    clipboardText,
    dialog,
    expect,
    guestPage,
    own,
    PASSWORD,
    test,
    toast
} from '../support/fixtures'

test.describe('inviting someone to a cloud team', () => {
    test('an invited person joins the team and gets no trial team of their own', async ({
        page,
        browser
    }, testInfo) => {
        const email = `${own(testInfo, 'joiner')}@cloud.test`

        await captureClipboard(page)
        await page.goto('/monoven/settings/team')
        await page.getByRole('button', { name: 'Invite someone' }).click()
        await dialog(page).getByLabel('Email address').fill(email)
        await dialog(page).getByRole('button', { name: 'Add to the team' }).click()
        await expect(toast(page)).toContainText(`Invitation sent to ${email}.`)

        await page
            .getByTestId('invitation-row')
            .filter({ hasText: email })
            .getByRole('button', { name: `Copy link for ${email}` })
            .click()

        const guest = await guestPage(browser, testInfo)

        await guest.goto(await clipboardText(page))
        await expect(guest.getByRole('heading', { level: 1 })).toHaveText('Join Monoven Ltd')
        await expect(guest.getByTestId('trust-note')).toHaveCount(0)

        await guest.getByLabel('Name').fill(`Joiner ${own(testInfo, 'joiner').split('-')[1]}`)
        await guest.getByLabel('Password', { exact: true }).fill(PASSWORD)
        await guest.getByLabel('Confirm password').fill(PASSWORD)
        await guest.getByRole('button', { name: 'Create account and join' }).click()

        await guest.waitForURL(/\/monoven\/dashboard$/)
        await expect(guest.getByRole('heading', { level: 1, name: 'Your fleet' })).toBeVisible()

        await guest.getByRole('button', { name: 'Account' }).click()

        const switcher = dialog(guest).getByTestId('team-switcher')

        await expect(switcher).toContainText('Monoven Ltd')
        await expect(switcher.getByRole('button', { name: /^Switch to / })).toHaveCount(0)
        await expect(switcher).not.toContainText("'s team")

        await guest.context().close()
    })
})
