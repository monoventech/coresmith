import { readFileSync } from 'node:fs'
import { dialog, expect, own, test, toast } from '../support/fixtures'

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

test.describe('database backups to s3 on coresmith cloud', () => {
    test.afterEach(async ({ page }) => {
        await page.goto('/monoven/dashboard')
    })

    test('a business team sends its databases to s3', async ({ page }, testInfo) => {
        await page.goto(`/${own(testInfo, 'vault')}/settings/team`)

        const card = page.getByTestId('offsite-backups-card')

        await card.getByRole('button', { name: 'Set up' }).click()
        await dialog(page).getByLabel('Region').fill('eu-central-1')
        await dialog(page).getByLabel('Bucket').fill('vault-backups')
        await dialog(page).getByLabel('Access key ID').fill('AKIAEXAMPLE0000000000')
        await dialog(page).getByLabel('Secret access key').fill('an-e2e-secret-access-key')
        await dialog(page).getByRole('button', { name: 'Save' }).click()

        await expect(toast(page)).toContainText('Saved.')
        await expect(card).toContainText('s3://vault-backups/')
        expect(await page.content()).not.toContain('an-e2e-secret-access-key')

        await card.getByRole('button', { name: 'Remove' }).click()
        await dialog(page).getByRole('button', { name: 'Remove destination' }).click()

        await expect(card.getByRole('button', { name: 'Set up' })).toBeVisible()
    })

    test('a pro team is told backups to s3 are part of business', async ({ page }) => {
        await page.goto('/monoven/settings/team')

        const card = page.getByTestId('offsite-backups-card')

        await expect(card).toContainText('Database backups to S3 are part of Business.')
        await expect(card.getByRole('button', { name: 'Set up' })).toHaveCount(0)
    })

    test('a failing database shows on the team tab and on its own page', async ({ page }) => {
        await page.goto('/atelier/settings/team')

        await expect(page.getByTestId('offsite-backups-card')).toContainText('Failing')
        await expect(page.getByTestId('offsite-backups-card')).toContainText('s3://atelier-backups/nightly')

        await page.goto('/atelier/dashboard')
        await page.getByTestId('server-card').getByRole('link', { name: 'atelier-01', exact: true }).click()
        await page
            .getByRole('navigation', { name: 'Server sections' })
            .getByRole('link', { name: 'Databases', exact: true })
            .click()
        await page.getByTestId('databases-table').getByRole('link').filter({ hasText: 'atelier_main' }).click()

        await expect(page.getByTestId('offsite-status')).toContainText('Last sent to S3')

        await page.goBack()
        await page.getByTestId('databases-table').getByRole('link').filter({ hasText: 'atelier_legacy' }).click()

        await expect(page.getByTestId('offsite-status')).toContainText('The bucket refused the upload')
    })
})
