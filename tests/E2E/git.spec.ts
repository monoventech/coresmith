import type { Page } from '@playwright/test'
import { asMember, dialog, expect, gotoSettings, isMobile, test } from './support/fixtures'

test.describe('git settings', () => {
    test('the connected GitHub App shows where it is installed and when its token rotates', async ({ page }) => {
        await gotoSettings(page, 'Git')

        const provider = page.getByTestId('provider-card')

        await expect(provider).toContainText('Installed')
        await expect(provider).toContainText(
            'Installed on monoventech · 4 repositories · contents read-only, commit statuses'
        )
        await expect(provider).toContainText(
            /Installation token expires in \d+ (minutes|hours), rotated automatically\./
        )
        await expect(page.getByRole('main')).toContainText(
            'GitHub App with fine-grained, per-repository permissions, not OAuth.'
        )
    })

    test('a team with GitHub connected can still connect GitLab', async ({ page }) => {
        await gotoSettings(page, 'Git')

        const card = page.getByTestId('connect-card')

        await expect(card.getByRole('link', { name: 'Connect GitLab' })).toBeVisible()
        await expect(card).toContainText('GitLab and Bitbucket tokens list repositories and register webhooks.')
    })

    test('card buttons and dialog footers are 44px tall on a phone', async ({ page }, testInfo) => {
        test.skip(!isMobile(testInfo), 'phone layout only')

        await gotoSettings(page, 'Git')

        const disconnect = page.getByTestId('provider-card').getByRole('button', { name: 'Disconnect' })

        expect((await disconnect.boundingBox())?.height).toBeGreaterThanOrEqual(44)

        await disconnect.click()

        const keep = dialog(page).getByRole('button', { name: 'Keep it' })

        await expect.poll(async () => (await keep.boundingBox())?.height).toBeGreaterThanOrEqual(44)

        await keep.click()
    })

    for (const [way, dismiss] of [
        ['Cancel', (page: Page) => dialog(page).getByRole('button', { name: 'Cancel' }).click()],
        ['Escape', (page: Page) => page.keyboard.press('Escape')]
    ] as const) {
        test(`closing the installation chooser with ${way} forgets the choices`, async ({ page }) => {
            let offered = false

            await page.route('**/settings/git', async (route) => {
                if (offered || route.request().headers()['x-inertia'] === undefined) {
                    await route.continue()

                    return
                }

                offered = true

                const response = await route.fetch()
                const body = await response.json()

                body.props.github.choose = {
                    app: 'Coresmith Monoven',
                    post_href: '/settings/source-providers/github_app/installations',
                    cancel_href: '/settings/source-providers/github_app/installations',
                    installations: [
                        { id: '1', account: 'first', repository_selection: 'all', refusal: null },
                        { id: '2', account: 'second', repository_selection: 'selected', refusal: null }
                    ]
                }

                await route.fulfill({ response, json: body })
            })
            await gotoSettings(page, 'Git')

            const chooser = page.getByTestId('github-chooser')
            const forgotten = page.waitForRequest(
                (request) => request.method() === 'DELETE' && request.url().endsWith('/github_app/installations')
            )

            await expect(chooser).toBeVisible()
            await dismiss(page)
            await forgotten
            await expect(chooser).toBeHidden()

            await page.reload()
            await expect(page.getByTestId('provider-card')).toBeVisible()
            await expect(chooser).toBeHidden()
        })
    }

    test('manage repositories opens the installation on GitHub', async ({ page }) => {
        await gotoSettings(page, 'Git')

        const manage = page.getByTestId('provider-card').getByRole('link', { name: 'Manage repositories' })

        await expect(manage).toHaveAttribute(
            'href',
            'https://github.com/organizations/monoventech/settings/installations/51842'
        )
        await expect(manage).toHaveAttribute('target', '_blank')
        await expect(manage).toHaveAttribute('rel', 'noopener noreferrer')
    })

    test('no credential reaches the page', async ({ page }) => {
        await gotoSettings(page, 'Git')

        const html = await page.content()

        expect(html).not.toContain('ghs_demo_never_rendered')
        expect(html).not.toContain('access_token')
        expect(html).not.toContain('refresh_token')
        expect(html).not.toContain('app_private_key')
        expect(html).not.toContain('BEGIN OPENSSH PRIVATE KEY')
        expect(html).not.toContain('client_secret')
        expect(html).not.toContain('webhook_secret')
        expect(html).not.toContain('private_key')
        expect(html).not.toContain('PRIVATE KEY')
    })

    test('every app with a deploy key is listed and opens the app', async ({ page }) => {
        await gotoSettings(page, 'Git')

        const keys = page.getByTestId('deploy-keys-card')

        await expect(keys).toContainText(
            'One Ed25519 key per app cloned over SSH, read-only. GitHub apps clone with a one-hour token instead.'
        )
        await keys
            .getByRole('link')
            .filter({ has: page.getByText('atlas', { exact: true }) })
            .click()

        await expect(page.getByRole('heading', { level: 1, name: 'atlas', exact: true })).toBeVisible()
    })

    test('webhooks say how deliveries are verified and why this panel polls', async ({ page }) => {
        await gotoSettings(page, 'Git')

        const webhooks = page.getByTestId('webhooks-card')

        await expect(webhooks).toContainText('HMAC-SHA256 verified')
        await expect(webhooks).toContainText('GitHub cannot reach 127.0.0.1, it only answers on this machine.')
    })

    test('disconnecting asks first and can be called off', async ({ page }) => {
        await gotoSettings(page, 'Git')

        await page.getByTestId('provider-card').getByRole('button', { name: 'Disconnect' }).click()

        await expect(dialog(page)).toContainText('Disconnect GitHub on monoventech?')
        await expect(dialog(page)).toContainText(
            'Coresmith stops using this installation and forgets its token. GitHub keeps the app installed until you uninstall it there. Apps that clone through it can still deploy public repositories.'
        )
        await dialog(page).getByRole('button', { name: 'Keep it' }).click()

        await expect(dialog(page)).toHaveCount(0)
        await expect(page.getByTestId('provider-card')).toBeVisible()
    })

    test("connecting another account goes through the panel to the app's install page", async ({ page }) => {
        await gotoSettings(page, 'Git')

        const captured = { redirect: '', navigation: false }

        await page.route('**/settings/source-providers/github_app/connect**', async (route) => {
            const response = await route.fetch({ maxRedirects: 0 })

            captured.redirect = response.headers()['location'] ?? ''
            captured.navigation = route.request().isNavigationRequest()
            await route.fulfill({ status: 200, contentType: 'text/html', body: '<!doctype html><title>stub</title>' })
        })
        await page.getByTestId('provider-card').getByRole('link', { name: 'Connect another account' }).click()

        await expect
            .poll(() => captured.redirect)
            .toMatch(/^https:\/\/github\.com\/apps\/coresmith-monoven\/installations\/new\?state=[A-Za-z0-9]{40}$/)
        expect(captured.navigation).toBe(true)
    })
})

asMember.describe('git settings as a member', () => {
    asMember('a member sees the connection but cannot change it', async ({ page }) => {
        await gotoSettings(page, 'Git')

        await expect(page.getByTestId('provider-card')).toContainText('Installed on monoventech')
        await expect(page.getByRole('button', { name: 'Disconnect' })).toHaveCount(0)
    })
})
