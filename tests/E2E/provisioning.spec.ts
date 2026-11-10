import { asMember, dialog, expect, gotoServer, own, test, toast } from './support/fixtures'

test.describe('checking a server before provisioning', () => {
    test('a server that has not been checked explains the check and offers it', async ({ page }) => {
        await gotoServer(page, 'empty-server')

        const report = page.getByTestId('audit-report')

        await expect(report).toContainText('Before provisioning')
        await expect(report.getByRole('button', { name: 'Check the server' })).toBeVisible()
    })

    test('a check in progress says nothing is being changed', async ({ page }) => {
        await gotoServer(page, 'checking-server')

        await expect(page.getByTestId('audit-report')).toContainText(
            'Nothing on the server changes while Coresmith looks.'
        )
    })

    test('blockers come first and provisioning waits for them', async ({ page }) => {
        await gotoServer(page, 'audit-server')

        const report = page.getByTestId('audit-report')

        await expect(report.getByTestId('audit-finding').first()).toHaveAttribute('data-severity', 'blocker')
        await expect(report).toContainText('Port 80 is in use by nginx')
        await expect(report).toContainText('Port 8080 is in use by node')
        await expect(report).toContainText('1 blocker')
        await expect(report.getByRole('button', { name: 'Proceed with provisioning' })).toBeDisabled()
    })

    test("the panel's own host is refused without advice that would break the panel", async ({ page }) => {
        await gotoServer(page, 'panel-host')

        const report = page.getByTestId('audit-report')

        await expect(report.getByTestId('audit-finding')).toHaveCount(1)
        await expect(report).toContainText('This server runs Coresmith itself')
        await expect(report).toContainText('Add a different server for your apps.')
        await expect(report).toContainText('1 blocker')
        await expect(report).not.toContainText('Rename or remove')
        await expect(report.getByRole('button', { name: 'Proceed with provisioning' })).toBeDisabled()
    })

    test("Coresmith's own machine, added by its address, is pointed to the Detach button it has and to This machine", async ({
        page
    }) => {
        await gotoServer(page, 'own-host')

        const report = page.getByTestId('audit-report')

        await expect(report).toContainText('This server runs Coresmith itself')
        await expect(report).toContainText(
            'Detach it here, then add it again with This machine under Provider in Add server.'
        )
        await expect(page.getByRole('button', { name: 'Detach', exact: true })).toBeVisible()
        await expect(page.getByTestId('server-state')).toHaveText('Not provisioned')
    })

    test('a web server in the way is stopped after confirming, and the server is checked again', async ({
        page
    }, testInfo) => {
        await gotoServer(page, own(testInfo, 'blocked'))

        await page.getByTestId('audit-report').getByRole('button', { name: 'Stop nginx' }).click()
        await expect(dialog(page)).toContainText('Any site it serves on this server goes offline')
        await dialog(page).getByRole('button', { name: 'Stop nginx' }).click()

        await expect(toast(page)).toContainText('Stopping nginx')
        await expect(page.getByTestId('audit-report')).toContainText(
            'Nothing on the server changes while Coresmith looks.'
        )
    })

    test('a server with nothing blocking it can be provisioned', async ({ page }, testInfo) => {
        await gotoServer(page, own(testInfo, 'ready'))

        await page.getByRole('button', { name: 'Proceed with provisioning' }).click()

        await expect(toast(page)).toContainText('Provisioning started.')
        await expect(page.getByTestId('provisioning-phases')).toContainText('Phase 1 of 8')
    })
})

test.describe('provisioning that failed', () => {
    test('the failed phase shows what went wrong and offers to resume from it', async ({ page }) => {
        await gotoServer(page, 'halfway-server')

        const phases = page.getByTestId('provisioning-phases')

        await expect(phases).toContainText('Failed at phase 4')
        await expect(phases.getByTestId('phase-error')).toContainText(
            'Directories failed. The command exited with code 1.'
        )
        await expect(phases.getByTestId('phase-error')).toContainText('Read-only file system')
        await expect(page.getByRole('button', { name: 'Retry from phase 4' })).toBeVisible()
    })

    test('starting over asks first', async ({ page }) => {
        await gotoServer(page, 'halfway-server')

        await page.getByRole('button', { name: 'Start over' }).click()
        await expect(dialog(page)).toContainText('safe to run again')
        await dialog(page).getByRole('button', { name: 'Cancel' }).click()

        await expect(dialog(page)).toHaveCount(0)
    })

    test('retrying resumes from the phase that failed', async ({ page }, testInfo) => {
        await gotoServer(page, own(testInfo, 'stalled'))

        await page.getByRole('button', { name: 'Retry from phase 4' }).click()

        await expect(toast(page)).toContainText('Retrying from phase 4.')
        await expect(page.getByTestId('provisioning-phases')).toContainText('Phase 4 of 8')
    })
})

asMember.describe('the check as a deployer', () => {
    asMember('a deployer sees the findings but cannot act on them', async ({ page }) => {
        await gotoServer(page, 'audit-server')

        const report = page.getByTestId('audit-report')

        await expect(report).toContainText('Port 80 is in use by nginx')
        await expect(report.getByRole('button')).toHaveCount(0)
    })
})
