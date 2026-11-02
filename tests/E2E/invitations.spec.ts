import type { Page } from '@playwright/test'
import {
    asGuest,
    asMember,
    captureClipboard,
    clipboardText,
    dialog,
    expect,
    EXPIRED_INVITATION,
    gotoSettings,
    guestPage,
    own,
    PASSWORD,
    test,
    toast,
    WAITING_INVITATION
} from './support/fixtures'

async function invite(page: Page, email: string) {
    await gotoSettings(page, 'Team')
    await page.getByRole('button', { name: 'Invite someone' }).click()
    await dialog(page).getByLabel('Email address').fill(email)
    await dialog(page).getByRole('button', { name: 'Add to the team' }).click()

    await expect(toast(page)).toContainText(
        `Invitation created. This panel cannot send email yet, so copy the link below and send it to ${email} yourself.`
    )
}

function invitation(page: Page, email: string) {
    return page.getByTestId('invitation-row').filter({ hasText: email })
}

test.describe('inviting someone without an account', () => {
    test('they open the copied link, create an account and land on the team', async ({ page, browser }, testInfo) => {
        const email = `${own(testInfo, 'joiner')}@coresmith.test`
        const name = `Joiner ${own(testInfo, 'joiner').split('-')[1]}`

        await captureClipboard(page)
        await invite(page, email)

        const row = invitation(page, email)

        await expect(row).toContainText('Deployer')
        await expect(row).toContainText('expires')

        await row.getByRole('button', { name: `Copy link for ${email}` }).click()
        await expect(row.getByRole('button', { name: `Copied for ${email}` })).toBeVisible()

        const link = await clipboardText(page)

        expect(link).toMatch(/^http:\/\/127\.0\.0\.1:\d+\/invitations\/[A-Za-z0-9]{40}$/)

        const guest = await guestPage(browser, testInfo)

        await guest.goto(link)
        await expect(guest.getByRole('heading', { level: 1 })).toHaveText('Join Monoven Ltd')
        await expect(guest.getByLabel('Email address')).toHaveValue(email)
        await expect(guest.getByTestId('trust-note')).toContainText(
            'By joining this Coresmith instance, your connected Git provider tokens will be accessible to the instance administrator.'
        )

        await guest.getByLabel('Name').fill(name)
        await guest.getByLabel('Password', { exact: true }).fill(PASSWORD)
        await guest.getByLabel('Confirm password').fill(PASSWORD)
        await guest.getByRole('button', { name: 'Create account and join' }).click()

        await guest.waitForURL(/\/monoven\/dashboard$/)
        await expect(guest.getByRole('heading', { level: 1, name: 'Your fleet' })).toBeVisible()
        await expect(toast(guest)).toContainText('Welcome to Monoven Ltd.')
        await guest.context().close()

        await page.reload()
        await expect(invitation(page, email)).toHaveCount(0)
        await expect(
            page
                .getByTestId('member-row')
                .filter({ hasText: email })
                .getByRole('combobox', { name: `Role for ${name}` })
        ).toHaveValue('member')
    })

    test('a revoked invitation leaves the list', async ({ page }, testInfo) => {
        const email = `${own(testInfo, 'revoked')}@coresmith.test`

        await invite(page, email)
        await invitation(page, email)
            .getByRole('button', { name: `Revoke the invitation to ${email}` })
            .click()

        await expect(dialog(page)).toContainText(`Revoke the invitation to ${email}?`)
        await dialog(page).getByRole('button', { name: 'Revoke invitation' }).click()

        await expect(toast(page)).toContainText('Invitation revoked.')
        await expect(invitation(page, email)).toHaveCount(0)
    })

    test('an invitation is listed without its link once the page is opened again', async ({ page }) => {
        await gotoSettings(page, 'Team')

        const row = invitation(page, 'waiting@coresmith.test')

        await expect(row).toContainText('Deployer')
        await expect(row.getByRole('button', { name: /^Revoke/ })).toBeVisible()
        await expect(row.getByRole('button', { name: /^Copy link/ })).toHaveCount(0)
    })

    test('someone signed in with another account is told to sign out first', async ({ page }) => {
        await page.goto(`/invitations/${WAITING_INVITATION}`)

        await expect(
            page.getByText('This invitation is for waiting@coresmith.test. Sign out, then open the link again.')
        ).toBeVisible()
        await expect(page.getByRole('button', { name: 'Sign out' })).toBeVisible()
    })
})

asMember.describe('invitations seen by a deployer', () => {
    asMember('a deployer sees who is invited but cannot copy the link or revoke', async ({ page }) => {
        await gotoSettings(page, 'Team')

        const row = invitation(page, 'waiting@coresmith.test')

        await expect(row).toContainText('Deployer')
        await expect(row.getByRole('button')).toHaveCount(0)
    })
})

asGuest.describe('an invitation that cannot be used', () => {
    asGuest('says so and offers to sign in', async ({ page }) => {
        await page.goto(`/invitations/${EXPIRED_INVITATION}`)

        await expect(page.getByRole('heading', { level: 1 })).toHaveText('Invitation expired')
        await expect(
            page.getByText('This invitation has expired or was already used. Ask someone on the team for a new one.')
        ).toBeVisible()
        await expect(page.getByRole('link', { name: 'Sign in' })).toBeVisible()
    })
})
