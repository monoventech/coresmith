import { asGuest, asMember, dialog, expect, own, pageData, test, toast } from './support/fixtures'

test.describe('single sign-on settings', () => {
    test.afterEach(async ({ page }) => {
        await page.goto('/monoven/dashboard')
    })

    test('an owner sets it up, is shown the record that proves the domain, and removes it', async ({
        page
    }, testInfo) => {
        const slug = own(testInfo, 'lab')

        await page.goto(`/${slug}/settings/team`)

        const card = page.getByTestId('sso-card')

        await expect(card.getByTestId('sso-redirect-uris')).toContainText('/sso/callback')

        await card.getByRole('button', { name: 'Set up single sign-on' }).click()
        await dialog(page).getByLabel('Issuer URL').fill(`https://idp.${slug}.invalid`)
        await dialog(page).getByLabel('Client ID').fill('coresmith-e2e')
        await dialog(page).getByLabel('Client secret').fill('an-e2e-client-secret')
        await dialog(page).getByLabel('Email domain').fill(`${slug}.test`)
        await dialog(page).getByRole('button', { name: 'Save' }).click()

        await expect(toast(page)).toContainText('Saved.')
        await expect(card).toContainText('Waiting for DNS')
        await expect(card).toContainText('Saved, never shown')
        await expect(card.getByTestId('sso-dns-record')).toContainText(`_coresmith-sso.${slug}.test`)

        const data = await pageData(page)

        expect(data).toContain('coresmith-e2e')
        expect(data).not.toContain('an-e2e-client-secret')

        await card.getByRole('button', { name: 'Check DNS' }).click()
        await expect(toast(page)).toContainText('The TXT record is not visible yet.')

        await card.getByRole('button', { name: 'Remove' }).click()
        await dialog(page).getByRole('button', { name: 'Remove single sign-on' }).click()

        await expect(toast(page)).toContainText('Removed.')
        await expect(card.getByRole('button', { name: 'Set up single sign-on' })).toBeVisible()
    })

    test('an issuer that is not https is refused in the dialog', async ({ page }, testInfo) => {
        const slug = own(testInfo, 'lab')

        await page.goto(`/${slug}/settings/team`)
        await page.getByTestId('sso-card').getByRole('button', { name: 'Set up single sign-on' }).click()
        await dialog(page).getByLabel('Issuer URL').fill(`http://idp.${slug}.invalid`)
        await dialog(page).getByLabel('Client ID').fill('coresmith-e2e')
        await dialog(page).getByLabel('Client secret').fill('an-e2e-client-secret')
        await dialog(page).getByLabel('Email domain').fill(`${slug}.test`)
        await dialog(page).getByRole('button', { name: 'Save' }).click()

        await expect(dialog(page)).toContainText('Use an https:// address, like https://login.example.com.')
    })
})

asMember.describe('single sign-on as a deployer', () => {
    asMember('a deployer sees single sign-on but cannot change it', async ({ page }) => {
        await page.goto('/monoven/settings/team')

        const card = page.getByTestId('sso-card')

        await expect(card).toContainText('Only an owner can change single sign-on.')
        await expect(card.getByRole('button', { name: 'Set up single sign-on' })).toHaveCount(0)
    })
})

asGuest.describe('signing in with single sign-on', () => {
    asGuest(
        'a team that uses it offers it on its sign-in page and says when the provider cannot be reached',
        async ({ page }) => {
            await page.goto('/northwind/login')

            await page.getByTestId('sso-entry').getByRole('link', { name: 'Continue with single sign-on' }).click()

            await expect(toast(page)).toContainText('Coresmith could not reach your identity provider.')
            await expect(page).toHaveURL(/\/northwind\/login$/)
        }
    )

    asGuest('the sign-in page leads to single sign-on, which finds the team from a work email', async ({ page }) => {
        await page.goto('/login')
        await page.getByRole('link', { name: 'Use single sign-on' }).click()

        await expect(page.getByRole('heading', { level: 1, name: 'Sign in with single sign-on' })).toBeVisible()

        await page.getByLabel('Work email').fill('someone@northwind.test')
        await page.getByRole('button', { name: 'Continue' }).click()

        await page.waitForURL(/\/northwind\/login$/)
        await expect(toast(page)).toContainText('Coresmith could not reach your identity provider.')
    })

    asGuest('an email domain without single sign-on is told to use a password', async ({ page }) => {
        await page.goto('/sso')
        await page.getByLabel('Work email').fill('someone@nowhere.test')
        await page.getByRole('button', { name: 'Continue' }).click()

        await expect(
            page.getByText(
                'No team signs in with single sign-on for that email domain. Sign in with your password instead.'
            )
        ).toBeVisible()
    })
})
