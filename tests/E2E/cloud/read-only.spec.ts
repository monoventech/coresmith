import { asCloudMember, expect, isMobile, noSidewaysScroll, test, toast } from '../support/fixtures'

test.describe('read-only teams on coresmith cloud', () => {
    test.afterEach(async ({ page }) => {
        await page.goto('/monoven/dashboard')
    })

    test('a lapsed team says so on every page and offers a plan', async ({ page }) => {
        await page.goto('/expired/dashboard')

        const banner = page.getByTestId('billing-banner')

        await expect(banner).toContainText("This team's trial has ended, so it is read-only.")
        await expect(banner).toContainText('Servers and apps keep running, and everything stays visible.')

        await banner.getByRole('link', { name: 'Choose a plan' }).click()
        await page.waitForURL(/\/expired\/settings\/billing$/)
    })

    test('a lapsed team cannot deploy', async ({ page }) => {
        await page.goto('/expired/dashboard')
        await page
            .getByRole('link', { name: /lapsed-01/ })
            .first()
            .click()
        await page
            .getByRole('link', { name: /lapsed-app/ })
            .first()
            .click()

        await page.getByRole('button', { name: 'Deploy', exact: true }).click()

        await expect(toast(page)).toContainText("This team's trial has ended, so it is read-only.")
    })

    test('a trial about to end warns', async ({ page }) => {
        await page.goto('/ending/dashboard')

        const banner = page.getByTestId('billing-banner')

        await expect(banner.locator('.font-semibold')).toHaveText(/^Your trial ends (in 2 days|tomorrow)\.$/)
        await expect(banner.getByRole('link', { name: 'Choose a plan' })).toBeVisible()
    })

    test('a failed payment warns but keeps working', async ({ page }) => {
        await page.goto('/overdue/dashboard')

        await expect(page.getByTestId('billing-banner')).toContainText('The last payment failed.')
        await expect(
            page.getByTestId('billing-banner').getByRole('link', { name: 'Update payment method' })
        ).toBeVisible()
    })

    test('the banner fits a phone', async ({ page }, testInfo) => {
        test.skip(!isMobile(testInfo), 'Only phones are narrow enough to scroll sideways')

        await page.goto('/expired/dashboard')

        await expect(page.getByTestId('billing-banner')).toBeVisible()
        await noSidewaysScroll(page)
    })
})

asCloudMember.describe('a deployer on a read-only team', () => {
    asCloudMember.afterEach(async ({ page }) => {
        await page.goto('/monoven/dashboard')
    })

    asCloudMember('a deployer on a lapsed team is told to ask an owner', async ({ page }) => {
        await page.goto('/expired/dashboard')

        const banner = page.getByTestId('billing-banner')

        await expect(banner).toContainText('Ask an owner to choose a plan.')
        await expect(banner.getByRole('link')).toHaveCount(0)
    })
})
