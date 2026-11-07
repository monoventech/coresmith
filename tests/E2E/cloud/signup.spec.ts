import { asGuest, expect, own } from '../support/fixtures'

asGuest.describe('signing up for coresmith cloud', () => {
    asGuest('a new account starts a trial and is asked to confirm its email', async ({ page }, testInfo) => {
        await page.goto('/register')

        await expect(
            page.getByText('Your team starts on a 14-day trial of Pro with one server. No card needed.')
        ).toBeVisible()

        await page.getByLabel('Name').fill('Signup Tester')
        await page.getByLabel('Email address').fill(`${own(testInfo, 'signup')}@cloud.test`)
        await page.getByLabel('Password', { exact: true }).fill('password-for-e2e')
        await page.getByLabel('Confirm password').fill('password-for-e2e')
        await page.getByRole('button', { name: 'Create account' }).click()

        await expect(page.getByRole('heading', { name: 'Check your inbox' })).toBeVisible()
    })
})
