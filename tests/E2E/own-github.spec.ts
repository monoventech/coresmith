import { asDeveloper, blockingViolations, expect, gotoSettings, test, useTheme } from './support/fixtures'

asDeveloper.describe("a developer's own GitHub", () => {
    asDeveloper('Your account, Git offers a GitHub App of its own, on its own account only', async ({ page }) => {
        await page.goto('/settings/account/git')

        const card = page.getByTestId('own-github')

        await expect(card.getByRole('heading', { name: 'Your own GitHub in Monoven Ltd' })).toBeVisible()
        await expect(card).toContainText('No one else in the team can list, read or add apps from them.')
        await expect(card.getByRole('button', { name: 'Create on GitHub' })).toBeVisible()
        await expect(card.getByText('An organization', { exact: true })).toHaveCount(0)
    })

    asDeveloper('the page passes axe in light and dark', async ({ page }) => {
        for (const theme of ['light', 'dark'] as const) {
            await useTheme(page, theme)
            await page.goto('/settings/account/git')
            await expect(page.getByTestId('own-github')).toBeVisible()

            expect(await blockingViolations(page)).toEqual([])
        }
    })
})

test.describe("members' own GitHub on the team's Git page", () => {
    test("an owner sees each member's own GitHub apart from the team's, and removing one asks first", async ({
        page
    }) => {
        await gotoSettings(page, 'Git')

        const card = page.getByTestId('members-github')

        await expect(card).toContainText('Noor Haddad')
        await expect(card).toContainText('noor-h')

        await card.getByRole('button', { name: 'Remove' }).click()
        await expect(card).toContainText('Their apps stop deploying from it.')
        await card.getByRole('button', { name: 'Keep' }).click()
        await expect(card).not.toContainText('Their apps stop deploying from it.')
    })
})
