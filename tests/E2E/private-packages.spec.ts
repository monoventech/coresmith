import type { Page } from '@playwright/test'
import {
    asMember,
    blockingViolations,
    dialog,
    expect,
    gotoSettings,
    isMobile,
    noSidewaysScroll,
    own,
    pageData,
    test,
    toast,
    useTheme
} from './support/fixtures'

async function openAdd(page: Page) {
    await gotoSettings(page, 'Git')
    await page.getByTestId('private-packages-card').getByRole('button', { name: 'Add credentials' }).first().click()

    return dialog(page)
}

test.describe('private packages', () => {
    test('an npm registry is added, edited and removed without its token reaching the page', async ({
        page
    }, testInfo) => {
        const registry = `https://${own(testInfo, 'npm')}.registry.example.com/`
        const token = `npm_${own(testInfo, 'e2e', '_')}_0000a1b2`
        const form = await openAdd(page)

        await form.getByLabel('Packages from').selectOption('npm')
        await expect(form.getByLabel('Username, optional')).toHaveCount(0)
        await form.getByLabel('Registry').fill(registry.slice(0, -1))
        await form.getByLabel('Scopes, optional').fill('@acme, @acme-ui')
        await form.getByLabel('Token').fill(token)
        await form.getByRole('button', { name: 'Add credentials' }).click()

        await expect(toast(page)).toContainText('Saved. Installs and builds use it from the next deploy.')

        const row = page.getByTestId('package-credential-row').filter({ hasText: registry })

        await expect(row).toContainText('npm')
        await expect(row).toContainText('@acme @acme-ui')
        await expect(row).toContainText('Every app')
        await expect(row).toContainText('ends in a1b2')
        expect(await pageData(page)).not.toContain(token)

        await row.getByRole('button', { name: 'Edit' }).click()
        await expect(dialog(page)).toContainText(`Edit credentials for ${registry}`)
        await expect(dialog(page).getByLabel('Scopes, optional')).toHaveValue('@acme @acme-ui')
        await dialog(page).getByLabel('Scopes, optional').fill('@acme')
        await dialog(page).getByRole('button', { name: 'Save' }).click()

        await expect(row).not.toContainText('@acme-ui')
        await expect(row).toContainText('ends in a1b2')

        await row.getByRole('button', { name: `Remove npm credentials for ${registry}` }).click()
        await expect(dialog(page)).toContainText(`Remove the credentials for ${registry}?`)
        await dialog(page).getByRole('button', { name: 'Remove' }).click()

        await expect(toast(page)).toContainText('Removed. The next deploy installs without it.')
        await expect(row).toHaveCount(0)
    })

    test('each source asks only for what it needs', async ({ page }) => {
        const form = await openAdd(page)

        await form.getByLabel('Packages from').selectOption('maven')
        await expect(form.getByLabel('Repository ID')).toBeVisible()
        await expect(form.getByLabel('Username', { exact: true })).toBeVisible()
        await expect(form.getByLabel('Scopes, optional')).toHaveCount(0)

        await form.getByLabel('Packages from').selectOption('git')
        await expect(form.getByLabel('Host and owner')).toBeVisible()
        await expect(form.getByLabel('Username, optional')).toBeVisible()
        await expect(form).toContainText('Leave it empty for GitHub, GitLab and Bitbucket tokens.')

        await form.getByRole('button', { name: 'Cancel' }).click()
        await expect(dialog(page)).toHaveCount(0)
    })

    test('a registry that is not https is refused with what to fix', async ({ page }) => {
        const form = await openAdd(page)

        await form.getByLabel('Packages from').selectOption('npm')
        await form.getByLabel('Registry').fill('http://npm.example.com')
        await form.getByLabel('Token').fill('npm_refused_token_0000')
        await form.getByRole('button', { name: 'Add credentials' }).click()

        await expect(form).toContainText("Enter the registry's https:// address, like https://npm.pkg.github.com.")
        await form.getByRole('button', { name: 'Cancel' }).click()
        await expect(page.getByTestId('package-credential-row').filter({ hasText: 'npm.example.com' })).toHaveCount(0)
    })

    for (const theme of ['light', 'dark'] as const) {
        test(`the card and its dialog pass axe in ${theme}`, async ({ page }) => {
            await useTheme(page, theme)

            const form = await openAdd(page)

            await form.getByLabel('Packages from').selectOption('npm')
            expect(await blockingViolations(page)).toEqual([])

            await page.keyboard.press('Escape')
            await expect(dialog(page)).toHaveCount(0)
            expect(await blockingViolations(page)).toEqual([])
        })
    }

    test('the card fits a phone and its button is 44px tall', async ({ page }, testInfo) => {
        test.skip(!isMobile(testInfo), 'phone layout only')

        await gotoSettings(page, 'Git')

        const add = page.getByTestId('private-packages-card').getByRole('button', { name: 'Add credentials' }).first()

        expect((await add.boundingBox())?.height).toBeGreaterThanOrEqual(44)
        await noSidewaysScroll(page)
    })
})

asMember.describe('private packages as a member', () => {
    asMember('a member sees the card but cannot add credentials', async ({ page }) => {
        await gotoSettings(page, 'Git')

        const card = page.getByTestId('private-packages-card')

        await expect(card).toContainText('Private packages')
        await expect(card).toContainText('An owner or admin can add them.')
        await expect(card.getByRole('button', { name: 'Add credentials' })).toHaveCount(0)
    })
})
