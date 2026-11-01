import { readFileSync } from 'node:fs'
import { expect, test } from '../support/fixtures'

test.describe('business features on coresmith cloud', () => {
    test.afterEach(async ({ page }) => {
        await page.goto('/monoven/dashboard')
    })

    test('a business team downloads its audit log', async ({ page }) => {
        await page.goto('/atelier/settings/team')

        const [download] = await Promise.all([
            page.waitForEvent('download'),
            page.getByTestId('audit-log-card').getByRole('link', { name: 'Export CSV' }).click()
        ])

        expect(download.suggestedFilename()).toMatch(/^coresmith-atelier-audit-\d{4}-\d{2}-\d{2}\.csv$/)

        const lines = readFileSync(await download.path(), 'utf8')
            .trim()
            .split('\n')

        expect(lines[0]).toBe('time,actor,actor_email,event,subject_type,subject,details')
        expect(lines.some((line) => line.includes('server_provisioned,server,atelier-01'))).toBe(true)
    })

    test('a pro team is told audit export is part of business', async ({ page }) => {
        await page.goto('/monoven/settings/team')

        const card = page.getByTestId('audit-log-card')

        await expect(card).toContainText('Audit log export is part of Business.')
        await expect(card.getByRole('link', { name: 'Export CSV' })).toHaveCount(0)
    })
})
