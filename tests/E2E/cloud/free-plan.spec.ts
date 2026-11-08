import { expect, test } from '../support/fixtures'

test.describe('a free plan on coresmith cloud', () => {
    test('a team on a free plan sees it is free, with nothing to buy', async ({ page }) => {
        await page.goto('/free/settings/billing')

        const plan = page.getByTestId('plan-card')

        await expect(plan.getByRole('heading')).toHaveText('Business')
        await expect(plan).toContainText('Free')
        await expect(plan).toContainText('Free of charge')
        await expect(plan).toContainText('$0')
        await expect(plan.getByRole('button', { name: /Change plan|Choose a plan/ })).toHaveCount(0)
        await expect(page.getByTestId('payment-card')).toContainText('No card needed while the plan is free.')
        await expect(page.getByText("No invoices. This team's plan is free of charge.")).toBeVisible()
        await expect(page.getByTestId('billing-banner')).toHaveCount(0)
    })

    test('a free plan that is ending counts down and offers a plan', async ({ page }) => {
        await page.goto('/free-soon/dashboard')

        const banner = page.getByTestId('billing-banner')

        await expect(banner).toContainText('Your free plan ends in 2 days.')
        await expect(banner).toContainText('this team becomes read-only. Choose a plan to keep making changes.')

        await banner.getByRole('link', { name: 'Choose a plan' }).click()

        const plan = page.getByTestId('plan-card')

        await expect(plan.getByRole('heading')).toHaveText('Starter')
        await expect(plan).toContainText('Free until')
        await expect(plan).toContainText('0 of 3 servers')
        await expect(plan.getByRole('button', { name: 'Choose a plan' })).toBeVisible()
    })

    test('a free team is told what its plan lacks without being sent to change it', async ({ page }) => {
        await page.goto('/free-soon/settings/team')

        const note = page.getByTestId('plan-note').first()

        await expect(note).toBeVisible()
        await expect(note.getByRole('link')).toHaveCount(0)
        await expect(note).not.toContainText('Ask an owner')
    })
})
