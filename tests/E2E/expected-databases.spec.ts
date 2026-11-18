import { asMember, blockingViolations, expect, gotoApplication, own, test, toast, useTheme } from './support/fixtures'

test.describe('a database the variables expect', () => {
    test('copied variables that expect a database the server lacks offer to create one, which connects it', async ({
        page
    }, testInfo) => {
        const name = own(testInfo, 'copied')

        await gotoApplication(page, 'bench-01', name)

        const banner = page.getByTestId('expected-database-banner')

        await expect(banner).toContainText('bench-01 has no MySQL database called forge.')
        await expect(banner).toContainText(`${name}'s variables expect it, so a deploy can't connect.`)
        const picker = banner.getByLabel('A database already on this server')
        const useIt = banner.getByRole('button', { name: 'Use it' })

        await expect(useIt).toBeDisabled()
        await picker.selectOption({ label: own(testInfo, 'reveal', '_') })
        await expect(useIt).toBeEnabled()

        await banner.getByRole('button', { name: `Create a MySQL database for ${name}` }).click()

        await expect(toast(page)).toContainText(
            `Creating a MySQL database for ${name}. Its settings are in the variables, so deploy once it's ready.`
        )
        await expect(banner).toHaveCount(0)
    })

    test.describe('accessibility', () => {
        test.use({ reducedMotion: 'reduce' })

        for (const theme of ['light', 'dark'] as const) {
            test(`the banner has no serious or critical violations in ${theme}`, async ({ page }) => {
                await useTheme(page, theme)
                await gotoApplication(page, 'bench-01', 'forge-copy')
                await expect(page.getByTestId('expected-database-banner')).toBeVisible()

                expect(await blockingViolations(page), `expected database banner in ${theme}`).toEqual([])
            })
        }
    })
})

asMember.describe('a database the variables expect, for a deployer', () => {
    asMember('a deployer is told why a deploy would fail, with nothing to press', async ({ page }) => {
        await gotoApplication(page, 'bench-01', 'forge-copy')

        const banner = page.getByTestId('expected-database-banner')

        await expect(banner).toContainText("Coresmith hasn't set up PostgreSQL on bench-01.")
        await expect(banner.getByRole('button')).toHaveCount(0)
    })
})
