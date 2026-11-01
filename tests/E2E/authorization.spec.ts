import {
    asMember,
    expect,
    gotoApplication,
    gotoServer,
    gotoServerTab,
    gotoSettings,
    isMobile
} from './support/fixtures'

asMember.describe('what a member may do', () => {
    asMember('a member sees the fleet but is not offered provisioning', async ({ page }) => {
        await page.goto('/dashboard')

        await expect(page.getByRole('heading', { level: 1, name: 'Your fleet' })).toBeVisible()
        await expect(page.getByTestId('server-card').first()).toBeVisible()
        await expect(page.getByRole('link', { name: 'Provision a server' })).toHaveCount(0)
    })

    asMember('a member reaching the provision page directly is refused', async ({ page }) => {
        const response = await page.goto('/servers/create')

        expect(response?.status()).toBe(403)
    })

    asMember('a member is not offered the server controls', async ({ page }) => {
        await gotoServer(page, 'anvil-01')

        await expect(page.getByRole('button', { name: 'Rename' })).toHaveCount(0)
        await expect(page.getByRole('button', { name: 'Reboot server' })).toHaveCount(0)
        await expect(page.getByRole('button', { name: 'Detach' })).toHaveCount(0)
    })

    asMember('a member is not offered the firewall or the prune action', async ({ page }) => {
        await gotoServerTab(page, 'outpost-01', 'Hardening')

        await expect(page.getByTestId('firewall').getByRole('button', { name: 'Apply' })).toHaveCount(0)
        await expect(page.getByRole('button', { name: 'Prune old releases' })).toHaveCount(0)
        await expect(page.getByRole('switch', { name: 'Audit logging' })).toBeDisabled()
    })

    asMember('a member sees the cron jobs but cannot change them', async ({ page }, testInfo) => {
        asMember.skip(
            isMobile(testInfo),
            'The phone app page keeps only Deploy, the toggles and the current release, as the mobile prototype draws it'
        )

        await gotoApplication(page, 'forge-01', 'atlas')

        const card = page.getByTestId('cron-jobs')

        await expect(card.getByTestId('cron-job').filter({ hasText: 'Laravel scheduler' })).toContainText(
            'Every minute'
        )
        await expect(card.getByRole('button', { name: 'Add cron job' })).toHaveCount(0)
        await expect(card.getByRole('button', { name: 'Edit Laravel scheduler' })).toHaveCount(0)
        await expect(card.getByRole('button', { name: 'Remove Laravel scheduler' })).toHaveCount(0)
        await expect(card.getByRole('button', { name: 'Show output' })).toBeVisible()
    })

    asMember('a member can deploy but cannot change the app or read its secrets', async ({ page }) => {
        await gotoApplication(page, 'forge-01', 'atlas')

        await expect(page.getByRole('button', { name: 'Deploy', exact: true })).toBeVisible()
        await expect(page.getByRole('button', { name: 'Rename' })).toHaveCount(0)
        await expect(page.getByRole('button', { name: 'Stop the app' })).toHaveCount(0)
        await expect(page.getByRole('button', { name: 'Edit variables' })).toHaveCount(0)
        await expect(page.getByRole('button', { name: 'Add domain' })).toHaveCount(0)
        await expect(page.getByRole('button', { name: 'Remove this app' })).toHaveCount(0)
        await expect(page.getByRole('switch', { name: 'Queue worker' })).toBeDisabled()
        await expect(page.getByTestId('env-preview')).toContainText('APP_ENV=••••••••')
        await expect(page.getByTestId('env-preview')).not.toContainText('production')
    })

    asMember('a member sees the panel settings but cannot change them', async ({ page }) => {
        await gotoSettings(page, 'General')

        await expect(page.getByTestId('panel-card')).toContainText('Coresmith')
        await expect(page.getByTestId('panel-card').getByRole('button', { name: 'Save' })).toHaveCount(0)
        await expect(page.getByTestId('backups-card').getByRole('button', { name: 'Back up now' })).toHaveCount(0)
    })

    asMember('a member is not offered database deletion', async ({ page }) => {
        await gotoServerTab(page, 'forge-01', 'Databases')
        await page.getByTestId('databases-table').getByRole('link').first().click()

        await expect(page.getByRole('button', { name: 'Delete this database' })).toHaveCount(0)
    })
})
