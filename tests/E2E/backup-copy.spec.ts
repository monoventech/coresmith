import { blockingViolations, dialog, expect, gotoSettings, test, toast } from './support/fixtures'

test.use({ reducedMotion: 'reduce' })

test.describe("copies of Coresmith's own backups", () => {
    test('an owner sends copies to S3 without the keys, sees which keys to keep, then stops', async ({ page }) => {
        await gotoSettings(page, 'General')
        const copy = page.getByTestId('backup-copy')

        await expect(copy).toContainText("Backups stay on this machine, so they're lost with it.")
        await copy.getByRole('button', { name: 'Copy to S3' }).click()
        await expect(dialog(page).getByRole('heading', { name: 'Copy backups to S3' })).toBeVisible()
        await dialog(page).getByLabel('Region').fill('eu-central-1')
        await dialog(page).getByLabel('Bucket').fill('coresmith-backups')
        await dialog(page).getByLabel('Path prefix').fill('beelink')
        await dialog(page).getByLabel('Access key ID').fill('AKIACOPY000000000000')
        await dialog(page).getByLabel('Secret access key').fill('a-copy-secret-access-key')
        await dialog(page).getByRole('button', { name: 'Save' }).click()

        await expect(toast(page)).toContainText("Saved. Each backup is copied to S3 without Coresmith's keys.")
        await expect(copy).toContainText('copied to, s3://coresmith-backups/beelink')
        await expect(copy.getByTestId('backup-copy-keys')).toContainText(
            "Copies leave out Coresmith's keys. Keep APP_KEY and CORESMITH_VAULT_KEY, from /opt/coresmith/shared/.env, in your password manager."
        )
        expect(await blockingViolations(page)).toEqual([])

        await copy.getByRole('button', { name: 'Stop copying' }).click()
        await dialog(page).getByRole('button', { name: 'Stop copying' }).click()

        await expect(toast(page)).toContainText('Removed. Backups stay on this machine.')
        await expect(copy.getByRole('button', { name: 'Copy to S3' })).toBeVisible()
    })
})
