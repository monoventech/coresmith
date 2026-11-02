import { asCloudMember, expect, isMobile, noSidewaysScroll, test } from '../support/fixtures'

test.describe('the billing tab on coresmith cloud', () => {
    test.afterEach(async ({ page }) => {
        await page.goto('/monoven/dashboard')
    })

    test('an owner sees the plan, the card and the invoices', async ({ page }) => {
        await page.goto('/monoven/settings/billing')

        const plan = page.getByTestId('plan-card')

        await expect(plan).toContainText('Pro')
        await expect(plan).toContainText('Active')
        await expect(plan).toContainText('$19 per month · renews')
        await expect(plan).toContainText('4 servers')
        await expect(page.getByTestId('payment-card')).toContainText('Visa •••• 4242')
        await expect(page.getByTestId('payment-card')).toContainText('expires 08/28')

        const rows = page.getByTestId('invoice-row')

        await expect(rows).toHaveCount(4)
        await expect(rows.first()).toContainText('MNV-0004')

        for (const row of await rows.all()) {
            await expect(row).toContainText('Paid')
        }

        await expect(page.getByTestId('invoices-card')).toContainText('Stripe emails each receipt to owner@cloud.test.')
    })

    test('the tab says how cloud bills', async ({ page }) => {
        await page.goto('/monoven/settings/billing')

        await expect(page.getByRole('main')).toContainText('Coresmith Cloud plans are billed monthly, per team.')
    })

    test('the billing tab is in view even where the tabs scroll', async ({ page }) => {
        await page.goto('/monoven/settings/billing')

        await expect(
            page.getByRole('navigation', { name: 'Settings' }).getByRole('link', { name: 'Billing', exact: true })
        ).toBeInViewport({ ratio: 0.9 })
    })

    test('the palette shows the plan', async ({ page }) => {
        await page.goto('/monoven/dashboard')
        await expect(page.getByRole('heading', { level: 1 })).toBeVisible()
        await page.keyboard.press('ControlOrMeta+K')

        const palette = page.getByRole('dialog', { name: 'Command palette' })

        await expect(palette.getByRole('option', { name: /Billing/ })).toContainText('Pro · $19 / month')
        await page.keyboard.press('Escape')
    })

    test('a trial shows what it includes', async ({ page }) => {
        await page.goto('/trial/settings/billing')

        const plan = page.getByTestId('plan-card')

        await expect(plan).toContainText('Trial')
        await expect(plan).toContainText('trial ends')
        await expect(plan).toContainText('1 of 1 server')
        await expect(page.getByTestId('payment-card')).toContainText('No card yet. You add one when you choose a plan.')
    })

    test('a lapsed team is shown as read-only', async ({ page }) => {
        await page.goto('/expired/settings/billing')

        await expect(page.getByTestId('plan-card')).toContainText('Read-only')
        await expect(page.getByTestId('plan-card')).toContainText('Trial ended')
        await expect(page.getByTestId('billing-banner')).toHaveCount(0)
    })

    test('a failed payment is shown on the plan', async ({ page }) => {
        await page.goto('/overdue/settings/billing')

        await expect(page.getByTestId('plan-card')).toContainText('Payment failed')
        await expect(page.getByTestId('invoice-row').filter({ hasText: 'Failed' })).toHaveCount(1)
    })

    test('the columns stack on a phone', async ({ page }, testInfo) => {
        test.skip(!isMobile(testInfo), 'Only phones are narrow enough to stack the columns')

        await page.goto('/monoven/settings/billing')

        const plan = await page.getByTestId('plan-card').boundingBox()
        const invoices = await page.getByTestId('invoices-card').boundingBox()

        expect(invoices?.y ?? 0).toBeGreaterThan(plan?.y ?? 0)
        await noSidewaysScroll(page)
    })
})

asCloudMember.describe('the billing tab for a deployer', () => {
    asCloudMember('a deployer sees the plan only', async ({ page }) => {
        await page.goto('/monoven/settings/billing')

        await expect(page.getByTestId('plan-card')).toContainText('Only an owner can change the plan.')
        await expect(page.getByTestId('invoices-card')).toContainText('Only an owner can see invoices.')
    })
})
