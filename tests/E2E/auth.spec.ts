import { asGuest, expect, own, PASSWORD, toast } from './support/fixtures'

const OWNER = 'owner@coresmith.test'

asGuest.describe('signing in', () => {
    asGuest('a guest visiting the panel is sent to sign in', async ({ page }) => {
        await page.goto('/dashboard')

        await expect(page).toHaveURL(/\/login$/)
    })

    asGuest('the sign in page carries the wordmark, the form and the Monoven mark', async ({ page }) => {
        await page.goto('/login')

        await expect(page.getByRole('heading', { level: 1, name: 'Sign in to your panel' })).toBeVisible()
        await expect(page.getByRole('link', { name: 'Coresmith home' })).toBeVisible()
        await expect(page.getByLabel('Email address')).toBeVisible()
        await expect(page.getByLabel('Password', { exact: true })).toBeVisible()
        await expect(page.getByText('Made by Monoven')).toBeVisible()
    })

    asGuest('the sign in page loads without a console error', async ({ page }) => {
        const problems: string[] = []

        page.on('console', (message) => {
            if (message.type() === 'error') {
                problems.push(message.text())
            }
        })
        page.on('pageerror', (error) => problems.push(error.message))

        await page.goto('/login')
        await expect(page.getByRole('heading', { level: 1, name: 'Sign in to your panel' })).toBeVisible()

        expect(problems).toEqual([])
    })

    asGuest('a wrong password is refused without saying which field was wrong', async ({ page }) => {
        await page.goto('/login')
        await page.getByLabel('Email address').fill(OWNER)
        await page.getByLabel('Password', { exact: true }).fill('not-the-password')
        await page.getByRole('button', { name: 'Sign in' }).click()

        await expect(page.getByText(/credentials do not match/)).toBeVisible()
        await expect(page).toHaveURL(/\/login$/)
    })

    asGuest('too many attempts say so and say when to try again', async ({ page }, testInfo) => {
        await page.goto('/login')

        const attempt = async () => {
            await page.getByLabel('Email address').fill(`${own(testInfo, 'throttled')}@coresmith.test`)
            await page.getByLabel('Password', { exact: true }).fill('not-the-password')
            await page.getByRole('button', { name: 'Sign in' }).click()
        }

        for (let count = 0; count < 5; count++) {
            await Promise.all([page.waitForResponse((response) => response.request().method() === 'POST'), attempt()])
            await expect(page.getByText(/credentials do not match/)).toBeVisible()
        }

        await attempt()

        await expect(toast(page)).toContainText(/Too many attempts\.\s*Try again in (\d+ seconds?|1 minute)\./)
        await expect(page.locator('dialog iframe')).toHaveCount(0)
        await expect(page).toHaveURL(/\/login$/)
    })

    asGuest('an unknown account is refused with the same message', async ({ page }) => {
        await page.goto('/login')
        await page.getByLabel('Email address').fill('nobody@coresmith.test')
        await page.getByLabel('Password', { exact: true }).fill(PASSWORD)
        await page.getByRole('button', { name: 'Sign in' }).click()

        await expect(page.getByText(/credentials do not match/)).toBeVisible()
    })

    asGuest('a valid sign in opens the fleet', async ({ page }) => {
        await page.goto('/login')
        await page.getByLabel('Email address').fill(OWNER)
        await page.getByLabel('Password', { exact: true }).fill(PASSWORD)
        await page.getByRole('button', { name: 'Sign in' }).click()

        await page.waitForURL(/\/dashboard$/)
        await expect(page.getByRole('heading', { level: 1, name: 'Your fleet' })).toBeVisible()
    })

    asGuest(
        'an account outside the team at the address is signed straight back out and told why',
        async ({ page }, testInfo) => {
            await page.goto('/acme/login')
            await page.getByLabel('Email address').fill(`${own(testInfo, 'invitee')}@coresmith.test`)
            await page.getByLabel('Password', { exact: true }).fill(PASSWORD)
            await page.getByRole('button', { name: 'Sign in' }).click()

            await expect(
                page.getByText(
                    "That account isn't a member of Acme Labs. Sign in with one that is, or ask an owner to invite you."
                )
            ).toBeVisible()
            await expect(page).toHaveURL(/\/acme\/login$/)
        }
    )

    asGuest('a reset link is requested from the sign in page', async ({ page }) => {
        await page.goto('/login')
        await page.getByRole('link', { name: 'Forgot it?' }).click()

        await expect(page.getByRole('heading', { level: 1, name: 'Reset your password' })).toBeVisible()

        await page.getByLabel('Email address').fill(OWNER)
        await page.getByRole('button', { name: 'Send the reset link' }).click()

        await expect(
            page.getByText(/We have emailed your password reset link|Please wait before retrying/)
        ).toBeVisible()
    })

    asGuest('the landing page leads to the panel', async ({ page }) => {
        await page.goto('/')

        await expect(
            page.getByRole('heading', { level: 1, name: 'Server management without the complexity.' })
        ).toBeVisible()
        await page.getByRole('link', { name: 'Open the panel' }).click()

        await expect(page).toHaveURL(/\/login$/)
    })

    asGuest('the landing page says how each deploy reads its repository', async ({ page }) => {
        await page.goto('/')

        const card = page.getByRole('heading', { name: 'Connect a repository' }).locator('..')

        await expect(card).toContainText(
            'A GitHub App with per-repo permissions. Each deploy clones with a read-only token that lasts an hour, or a read-only Ed25519 deploy key for repositories added by URL.'
        )
        await expect(card).not.toContainText('deploy key per app')
    })

    asGuest('the landing page links to the source and the self-hosting guide', async ({ page }) => {
        await page.goto('/')

        await expect(page.getByRole('link', { name: 'Self-host it' })).toHaveAttribute(
            'href',
            'https://github.com/monoven/coresmith#self-hosting'
        )
        await expect(page.getByRole('link', { name: 'Browse on GitHub' })).toHaveAttribute(
            'href',
            'https://github.com/monoven/coresmith'
        )
    })
})

asGuest.describe('signing out', () => {
    asGuest('signing out from the account dialog ends the session', async ({ page }) => {
        await page.goto('/login')
        await page.getByLabel('Email address').fill('signout@coresmith.test')
        await page.getByLabel('Password', { exact: true }).fill(PASSWORD)
        await page.getByRole('button', { name: 'Sign in' }).click()
        await page.waitForURL(/\/dashboard$/)

        await page.getByRole('button', { name: 'Account' }).click()
        await page.getByTestId('sign-out').click()

        await page.waitForURL((url) => !url.pathname.startsWith('/dashboard'))
        await page.goto('/dashboard')
        await expect(page).toHaveURL(/\/login$/)
    })
})
