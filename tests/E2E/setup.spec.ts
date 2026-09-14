import { expect, test } from './support/fixtures'

test.describe('setup', () => {
    test('a finished setup stays reachable for reference', async ({ page }) => {
        await page.goto('/setup')

        await expect(page.getByRole('heading', { level: 1, name: 'Set up Coresmith' })).toBeVisible()
        await expect(page.getByRole('main')).toContainText('Every step is done.')

        const steps = page.getByRole('navigation', { name: 'Setup steps' })

        await expect(steps.getByRole('button')).toHaveCount(4)
        await expect(steps.getByRole('button', { name: /First deploy/ })).toHaveAttribute('aria-current', 'step')
        await expect(page.getByTestId('setup-step')).toContainText('Deploy your first app')
    })

    test('an earlier step can be revisited', async ({ page }) => {
        await page.goto('/setup')

        await page.getByRole('navigation', { name: 'Setup steps' }).getByRole('button', { name: /Connect GitHub/ }).click()

        await expect(page.getByTestId('setup-step')).toContainText('Connect GitHub')
        await expect(page.getByTestId('setup-step')).toContainText('Connected as monoventech')
    })

    test('provider provisioning is shown as not available yet, with the reason', async ({ page }) => {
        await page.goto('/setup?step=1')

        await expect(page.getByTestId('setup-step')).toContainText('Arrives with provider integrations.')
        await expect(page.getByTestId('setup-step')).toContainText('Use an existing VM')
    })
})
