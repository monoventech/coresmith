import type { Page, TestInfo } from '@playwright/test'
import {
    asCloudLeaver,
    asCloudMember,
    blockingViolations,
    expect,
    own,
    PASSWORD,
    test,
    useTheme
} from '../support/fixtures'

function dangerZone(page: Page) {
    return page.getByTestId('danger-zone')
}

function partingTeam(testInfo: TestInfo): string {
    return own(testInfo, 'Parting', ' ')
}

async function askToDelete(page: Page, team: string) {
    await dangerZone(page).getByRole('button', { name: 'Delete this team' }).click()
    await page.getByRole('dialog').getByLabel(`Type ${team} to confirm`).fill(team)
    await page.getByRole('dialog').getByRole('button', { name: 'Delete it' }).click()
}

asCloudLeaver.describe('deleting a team on coresmith cloud', () => {
    asCloudLeaver(
        'an owner alone on a trial team deletes it, lands on the page for people with no team and starts another',
        async ({ page }, testInfo) => {
            const team = partingTeam(testInfo)

            await page.goto('/settings')

            await expect(dangerZone(page)).toContainText(
                'Deleting the team detaches every server. The servers, and everything on them, keep running.'
            )

            await dangerZone(page).getByRole('button', { name: 'Delete this team' }).click()

            const dialog = page.getByRole('dialog')

            await expect(dialog.getByRole('heading', { name: `Delete ${team}?` })).toBeVisible()
            await expect(dialog).toContainText(
                'Its 3 servers are detached and keep running as-is. Deploys stop until you add them to another team.'
            )
            await expect(dialog).toContainText(
                'One of them was created at Hetzner Cloud, so it keeps running and billing there until you destroy it at Hetzner Cloud, or from the panel before you delete the team.'
            )
            await expect(dialog).toContainText(
                `Coresmith could not confirm whether Hetzner Cloud created ${own(testInfo, 'parting')}-03. Check your Hetzner Cloud account and delete it there if it exists.`
            )
            await expect(dialog.getByRole('button', { name: 'Delete it' })).toBeDisabled()

            await dialog.getByLabel(`Type ${team} to confirm`).fill(team)
            await dialog.getByRole('button', { name: 'Delete it' }).click()

            await page.waitForURL(/\/(start|user\/confirm-password)$/)

            if (page.url().endsWith('/user/confirm-password')) {
                await page.getByLabel('Password', { exact: true }).fill(PASSWORD)
                await page.getByRole('button', { name: 'Confirm' }).click()
                await page.waitForURL(/\/settings$/)

                await askToDelete(page, team)
                await page.waitForURL(/\/start$/)
            }

            await expect(page.getByRole('heading', { level: 1 })).toHaveText('Your team was deleted')
            await expect(page.getByTestId('trial-note')).toHaveText(
                'This address already had a trial, so a new team starts without one. Choose a plan from Billing to add servers.'
            )
            expect(await blockingViolations(page), 'the page for people with no team in light').toEqual([])

            await useTheme(page, 'dark')
            await page.reload()

            await expect(page.getByRole('heading', { level: 1 })).toHaveText("You're not on a team")
            expect(await blockingViolations(page), 'the page for people with no team in dark').toEqual([])

            await page.getByRole('button', { name: 'Start a team' }).click()

            await page.waitForURL(/\/[a-z0-9-]+\/dashboard$/)
            await expect(page.getByText("Started Morgan's team.")).toBeVisible()
        }
    )
})

test.describe('what stops a team being deleted', () => {
    test.afterEach(async ({ page }) => {
        await page.goto('/monoven/dashboard')
    })

    test('a plan that renews has to end first', async ({ page }) => {
        await page.goto('/monoven/settings')

        await expect(dangerZone(page)).toContainText('End the plan from Billing first, then delete the team.')
        await expect(dangerZone(page).getByRole('button', { name: 'Delete this team' })).toBeDisabled()
    })

    test('a team with other members has to remove them first', async ({ page }) => {
        await page.goto('/expired/settings')

        await expect(dangerZone(page)).toContainText(
            'Remove the other members first, so nobody loses access by surprise.'
        )
        await expect(dangerZone(page).getByRole('button', { name: 'Delete this team' })).toBeDisabled()
    })

    test('the owner of a deletable team can open the dialog and keep the team', async ({ page }) => {
        await page.goto('/trial/settings')

        await dangerZone(page).getByRole('button', { name: 'Delete this team' }).click()
        await expect(page.getByRole('dialog')).toContainText(
            'Its server is detached and keeps running as-is. Deploys stop until you add it to another team.'
        )
        await page.getByRole('dialog').getByRole('button', { name: 'Keep the team' }).click()

        await expect(page.getByRole('dialog')).toHaveCount(0)
        await expect(dangerZone(page)).toBeVisible()
    })
})

asCloudMember.describe('a deployer on coresmith cloud', () => {
    asCloudMember('is not offered team deletion', async ({ page }) => {
        await page.goto('/monoven/settings')

        await expect(dangerZone(page)).toBeVisible()
        await expect(dangerZone(page).getByRole('button', { name: 'Delete this team' })).toHaveCount(0)
    })
})
