import { dialog, expect, own, test, toast } from '../support/fixtures'

test.describe('changing the plan on coresmith cloud', () => {
    test.afterEach(async ({ page }) => {
        await page.goto('/monoven/dashboard')
    })

    test('a trial goes to stripe to pay', async ({ page }, testInfo) => {
        await page.goto(`/${own(testInfo, 'pay')}/settings/billing`)
        await page.getByRole('button', { name: 'Choose a plan' }).click()

        await expect(dialog(page).getByRole('heading', { name: 'Choose a plan' })).toBeVisible()
        await expect(dialog(page).getByRole('button', { name: 'Continue to Stripe' })).toBeDisabled()

        await dialog(page).getByRole('radio', { name: 'Pro', exact: true }).click()
        await dialog(page).getByRole('button', { name: 'Continue to Stripe' }).click()

        await expect(page.getByRole('heading', { name: 'Stripe Checkout (test double)' })).toBeVisible()
    })

    test('a plan changes at once', async ({ page }, testInfo) => {
        await page.goto(`/${own(testInfo, 'swap')}/settings/billing`)
        await page.getByRole('button', { name: 'Change plan' }).click()

        await expect(dialog(page).getByRole('radio', { name: 'Pro', exact: true })).toBeChecked()
        await expect(dialog(page).getByRole('button', { name: 'Save' })).toBeDisabled()

        await dialog(page).getByRole('radio', { name: 'Business', exact: true }).click()
        await dialog(page).getByRole('button', { name: 'Save' }).click()

        await expect(toast(page)).toContainText('Saved.')
        await expect(dialog(page)).toBeHidden()
        await expect(page.getByTestId('plan-card').getByRole('heading')).toHaveText('Business')
    })

    test('a downgrade lists what has to go first', async ({ page }) => {
        await page.goto('/fleet/settings/billing')
        await page.getByRole('button', { name: 'Change plan' }).click()
        await dialog(page).getByRole('radio', { name: 'Starter', exact: true }).click()

        const consequences = dialog(page).getByTestId('plan-consequences')

        await expect(consequences).toContainText('Starter includes 3 servers and this team has 5. Detach 2 first.')

        for (const name of ['fleet-01', 'fleet-02', 'fleet-03', 'fleet-04', 'fleet-05']) {
            await expect(consequences.getByRole('link', { name, exact: true })).toBeVisible()
        }

        await expect(consequences).toContainText('Remove the other 2 members first.')
        await expect(consequences).toContainText('Remove deploy.bigfleet.test first.')
        await expect(dialog(page).getByRole('button', { name: 'Save' })).toBeDisabled()

        await dialog(page).getByRole('radio', { name: 'Business', exact: true }).click()

        await expect(dialog(page).getByTestId('plan-blocker')).toHaveCount(0)
        await expect(dialog(page).getByRole('button', { name: 'Save' })).toBeEnabled()
    })

    test('moving to self-host ends the plan at the period end', async ({ page }, testInfo) => {
        await page.goto(`/${own(testInfo, 'end')}/settings/billing`)
        await page.getByRole('button', { name: 'Move to self-host' }).click()

        await expect(dialog(page).getByRole('radio', { name: 'Self-hosted' })).toBeChecked()
        await expect(dialog(page).getByTestId('plan-consequences')).toContainText('Coresmith Cloud stops on')
        await expect(dialog(page).getByRole('link', { name: 'Read how to self-host' })).toHaveAttribute(
            'href',
            'https://github.com/monoven/coresmith#self-hosting'
        )

        await dialog(page).getByRole('button', { name: 'End Cloud plan' }).click()

        await expect(toast(page)).toContainText('Saved.')
        await expect(page.getByTestId('plan-card')).toContainText('Ending')
    })

    test('an ending plan can be kept', async ({ page }, testInfo) => {
        await page.goto(`/${own(testInfo, 'keep')}/settings/billing`)
        await expect(page.getByTestId('plan-card')).toContainText('Ending')

        await page.getByRole('button', { name: 'Keep my plan' }).click()

        await expect(toast(page)).toContainText('Saved.')
        await expect(page.getByTestId('plan-card')).toContainText('Active')
        await expect(page.getByTestId('plan-card')).not.toContainText('Ending')
    })

    test('the card is changed at stripe', async ({ page }) => {
        await page.goto('/monoven/settings/billing')
        await page.getByTestId('payment-card').getByRole('button', { name: 'Update' }).click()

        await expect(page.getByRole('heading', { name: 'Stripe billing portal (test double)' })).toBeVisible()
    })

    test('a failed payment with no card on file can still reach stripe', async ({ page }) => {
        await page.goto('/no-card/dashboard')
        await page.getByTestId('billing-banner').getByRole('link', { name: 'Update payment method' }).click()
        await page.waitForURL(/\/no-card\/settings\/billing$/)

        const card = page.getByTestId('payment-card')

        await expect(card).toContainText('No card shown here yet. Add or update it at Stripe.')

        await card.getByRole('button', { name: 'Update' }).click()

        await expect(page.getByRole('heading', { name: 'Stripe billing portal (test double)' })).toBeVisible()
    })

    test('coming back from checkout', async ({ page }) => {
        await page.goto('/monoven/settings/billing?checkout=cancelled')

        await expect(page.getByTestId('checkout-status')).toHaveText('Checkout was cancelled, so nothing was charged.')

        await page.goto('/monoven/settings/billing?checkout=success')

        await expect(page.getByTestId('checkout-status')).toHaveText("You're on Pro.")
    })

    test('a checkout waits for stripe, then says what to do', async ({ page }) => {
        await page.clock.install()
        await page.goto('/trial/settings/billing?checkout=success')

        await expect(page.getByTestId('checkout-status')).toHaveText(
            'Checkout finished. The plan appears here as soon as Stripe confirms it.'
        )

        await page.clock.runFor(61_000)

        await expect(page.getByTestId('checkout-status')).toHaveText(
            'Stripe has not confirmed the plan yet. Refresh in a minute.'
        )
    })
})
