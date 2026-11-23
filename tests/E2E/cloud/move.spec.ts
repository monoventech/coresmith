import { generateKeyPairSync } from 'node:crypto'
import { dialog, expect, own, test, toast } from '../support/fixtures'

function moveCode(): string {
    const raw = generateKeyPairSync('x25519').publicKey.export({ format: 'der', type: 'spki' }).subarray(-32)
    const claims = {
        v: 1,
        id: '01JMOVEE2E00000000000000000',
        key: Buffer.from(raw).toString('base64'),
        until: Math.floor(Date.now() / 1000) + 24 * 3600,
        panel: 'https://panel.example.com'
    }

    return `csmove1_${Buffer.from(JSON.stringify(claims)).toString('base64url')}`
}

test.describe('moving a team to your own panel', () => {
    test.afterEach(async ({ page }) => {
        await page.goto('/monoven/dashboard')
    })

    test('a code from your panel pauses the team and downloads its move file', async ({ page }, testInfo) => {
        const slug = own(testInfo, 'move')

        await page.goto(`/${slug}/settings/billing`)
        await page.getByRole('button', { name: 'Move to self-host' }).click()
        await dialog(page).getByLabel('Move code').fill(moveCode())

        const download = page.waitForEvent('download')
        await dialog(page).getByRole('button', { name: 'Download move file' }).click()

        expect((await download).suggestedFilename()).toBe(`${slug}.coresmith-move`)
        await expect(page.getByTestId('move-card')).toContainText('Moving to self-hosted Coresmith')
        await expect(page.getByTestId('move-card')).toContainText('https://panel.example.com')
        await expect(page.getByTestId('move-card').getByRole('link', { name: 'Download move file' })).toBeVisible()
    })

    test('text that is not a move code is refused', async ({ page }) => {
        await page.goto('/trial/settings/billing')
        await page.getByRole('button', { name: 'Move to self-host' }).click()
        await dialog(page).getByLabel('Move code').fill('not-a-move-code')
        await dialog(page).getByRole('button', { name: 'Download move file' }).click()

        await expect(dialog(page)).toContainText(
            "That isn't a move code. Copy it again from Settings, General on your self-hosted Coresmith."
        )
    })

    test('a moving team says so on every page and can stay on coresmith cloud', async ({ page }, testInfo) => {
        await page.goto(`/${own(testInfo, 'moving')}/dashboard`)
        const banner = page.getByTestId('billing-banner')

        await expect(page.getByTestId('server-card')).toContainText('Moving out')

        await expect(banner).toContainText('This team is moving to self-hosted Coresmith.')
        await banner.getByRole('link', { name: 'See the move' }).click()

        await page.getByTestId('move-card').getByRole('button', { name: 'Cancel the move' }).click()

        await expect(toast(page)).toContainText('Saved. Coresmith Cloud manages this team again.')
        await expect(page.getByTestId('move-card')).toHaveCount(0)
    })
})

test.describe('moving a self-hosted team in', () => {
    test.afterEach(async ({ page }) => {
        await page.goto('/monoven/dashboard')
    })

    test('an empty team makes a move code for self-hosted coresmith', async ({ page }, testInfo) => {
        await page.goto(`/${own(testInfo, 'fresh')}/settings`)
        const card = page.getByTestId('move-team-card')

        await expect(card).toContainText('Move a team from self-hosted Coresmith')
        await card.getByRole('button', { name: 'Create a move code' }).click()

        await expect(card.getByTestId('move-code')).toContainText('csmove1_')
        await expect(card).toContainText('In your self-hosted Coresmith, paste this code')
    })

    test('a move file brings the team in and its server waits to be taken over', async ({ page }, testInfo) => {
        const project = own(testInfo, '', '')

        await page.goto(`/arriving-${project}/settings`)
        const card = page.getByTestId('move-team-card')

        await expect(card.getByTestId('move-summary')).toHaveText(
            `Arrivals ${project} moves into this team from https://coresmith.example.com with 1 server, 1 app and 1 database.`
        )
        await card.getByRole('button', { name: 'Move team' }).click()

        await expect(toast(page)).toContainText('Moved. Coresmith Cloud is taking over 1 server.')
        await expect(page.getByText(`arrivals-${project}-01`).first()).toBeVisible()
    })

    test('a team bigger than the trial waits for a plan that fits', async ({ page }) => {
        await page.goto('/arriving-big/settings')
        const card = page.getByTestId('move-team-card')

        await expect(card.getByTestId('move-plan')).toContainText(
            'Arrivals big has 2 servers, and the trial includes 1.'
        )
        await expect(card.getByRole('link', { name: 'Go to Billing' })).toBeVisible()
        await expect(card.getByRole('button', { name: 'Move team' })).toBeDisabled()
    })

    test('setup sends someone coming from self-hosted coresmith to the move', async ({ page }) => {
        await page.goto('/arriving-big/setup')
        await page.getByRole('link', { name: 'Move your team here instead' }).click()

        await page.waitForURL(/\/arriving-big\/settings#move-team$/)
        await expect(page.getByTestId('move-team-card')).toBeVisible()
    })
})
