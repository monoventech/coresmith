import { generateKeyPairSync } from 'node:crypto'
import { asMover, blockingViolations, expect, own, test, toast, useTheme } from './support/fixtures'

test.use({ reducedMotion: 'reduce' })

asMover.describe('moving a team in from coresmith cloud', () => {
    asMover('the move file says what moves before anything changes', async ({ page }, testInfo) => {
        const code = own(testInfo, '', '')

        await page.goto(`/${own(testInfo, 'landed')}/settings`)
        const card = page.getByTestId('move-team-card')

        await expect(card.getByTestId('move-summary')).toHaveText(
            `Arrivals ${code} moves here from https://app.coresmith.io with 1 server, 1 app and 1 database.`
        )
        await expect(card).toContainText(`arrivals-${code}-01`)
        await expect(card).toContainText('To reconnect afterwards: GitHub for 1 app.')
        await expect(card).toContainText('1 teammate gets an invitation.')
    })

    for (const theme of ['light', 'dark'] as const) {
        asMover(`the move card and a server moving here are accessible in ${theme}`, async ({ page }, testInfo) => {
            await useTheme(page, theme)
            await page.goto(`/${own(testInfo, 'landed')}/settings`)
            await expect(page.getByTestId('move-team-card')).toBeVisible()
            expect(await blockingViolations(page)).toEqual([])

            await page.goto(`/${own(testInfo, 'landed')}/dashboard`)
            await page
                .getByTestId('server-card')
                .getByRole('link', { name: `${own(testInfo, 'landed')}-01`, exact: true })
                .click()
            await expect(page.getByTestId('server-move')).toBeVisible()
            expect(await blockingViolations(page)).toEqual([])
        })
    }

    asMover('moving the team brings it here and its server waits to be taken over', async ({ page }, testInfo) => {
        const code = own(testInfo, '', '')

        await page.goto(`/${own(testInfo, 'landed')}/settings`)
        await page.getByTestId('move-team-card').getByRole('button', { name: 'Move team' }).click()

        await expect(page).toHaveURL(new RegExp(`/arrivals-${code}/dashboard$`))
        await expect(toast(page)).toContainText(
            `Moved. Arrivals ${code} is here, and its servers are being taken over.`
        )
        await expect(page.getByTestId('server-card')).toContainText('Moving here')
    })

    asMover('a server whose move stopped says why and can be tried again', async ({ page }, testInfo) => {
        const server = `${own(testInfo, 'landed')}-01`

        await page.goto(`/${own(testInfo, 'landed')}/dashboard`)
        await page.getByTestId('server-card').getByRole('link', { name: server, exact: true }).click()
        const panel = page.getByTestId('server-move')

        await expect(panel).toContainText('The move stopped')
        await expect(panel).toContainText(`Coresmith couldn't reach ${server} at`)

        await panel.getByRole('button', { name: 'Try again' }).click()

        await expect(toast(page)).toContainText(`Trying ${server} again.`)
        await expect(panel).toContainText('Moving here')
        await expect(panel.getByRole('button', { name: 'Try again' })).toHaveCount(0)
    })
})

test('an owner of the panel makes a move code to paste into coresmith cloud', async ({ page }) => {
    await page.goto('/monoven/settings')
    const card = page.getByTestId('move-team-card')

    await card.getByRole('button', { name: 'Create a move code' }).click()

    await expect(toast(page)).toContainText('Saved. Paste the move code into the Coresmith the team is leaving.')
    await expect(card.getByTestId('move-code')).toContainText('csmove1_')
    await expect(card).toContainText("In Coresmith Cloud that's Billing, Move to self-host.")

    await card.getByRole('button', { name: 'Remove the code' }).click()

    await expect(toast(page)).toContainText('Removed.')
    await expect(card.getByRole('button', { name: 'Create a move code' })).toBeVisible()
})

function cloudMoveCode(): string {
    const raw = generateKeyPairSync('x25519').publicKey.export({ format: 'der', type: 'spki' }).subarray(-32)
    const claims = {
        v: 1,
        id: '01JMOVEAWAYE2E000000000000',
        key: Buffer.from(raw).toString('base64'),
        until: Math.floor(Date.now() / 1000) + 24 * 3600,
        panel: 'https://app.coresmith.io',
        edition: 'cloud'
    }

    return `csmove1_${Buffer.from(JSON.stringify(claims)).toString('base64url')}`
}

asMover.describe('moving a team to coresmith cloud', () => {
    asMover(
        'a code from coresmith cloud pauses the team, downloads its file and can be undone',
        async ({ page }, testInfo) => {
            const slug = own(testInfo, 'leaving')

            await page.goto(`/${slug}/settings`)
            const card = page.getByTestId('move-away-card')
            await card.getByLabel('Move code').fill(cloudMoveCode())

            const download = page.waitForEvent('download')
            await card.getByRole('button', { name: 'Download move file' }).click()

            expect((await download).suggestedFilename()).toBe(`${slug}.coresmith-move`)
            await expect(card).toContainText('Moving to Coresmith Cloud')
            await expect(page.getByText('This team is moving to Coresmith Cloud.')).toHaveCount(0)

            await page.goto(`/${slug}/dashboard`)
            await expect(page.getByText('This team is moving to Coresmith Cloud.')).toBeVisible()
            await page.getByRole('link', { name: 'See the move' }).click()
            await page.waitForURL(new RegExp(`/${slug}/settings$`))

            await card.getByRole('button', { name: 'Cancel the move' }).click()

            await expect(toast(page)).toContainText('Saved. Coresmith manages this team again.')
            await expect(card).toContainText('Move this team to Coresmith Cloud')
        }
    )

    asMover('a server coresmith cloud could not reach is named before anything pauses', async ({ page }, testInfo) => {
        const slug = own(testInfo, 'homelab')

        await page.goto(`/${slug}/settings`)
        const card = page.getByTestId('move-away-card')
        await card.getByLabel('Move code').fill(cloudMoveCode())
        await card.getByRole('button', { name: 'Download move file' }).click()

        await expect(card.getByRole('alert')).toContainText(`${slug}-01 is at 192.168.0.`)
        await expect(card.getByRole('alert')).toContainText("which Coresmith Cloud can't reach")
        await expect(card).toContainText('Move this team to Coresmith Cloud')
    })

    for (const theme of ['light', 'dark'] as const) {
        asMover(`the move-away card is accessible before and during a move in ${theme}`, async ({ page }, testInfo) => {
            await useTheme(page, theme)

            await page.goto(`/${own(testInfo, 'homelab')}/settings`)
            await expect(page.getByTestId('move-away-card')).toContainText('Move this team to Coresmith Cloud')
            expect(await blockingViolations(page), `before, ${theme}`).toEqual([])

            await page.goto(`/${own(testInfo, 'on-the-way')}/settings`)
            await expect(page.getByTestId('move-away-card')).toContainText('Moving to Coresmith Cloud')
            expect(await blockingViolations(page), `during, ${theme}`).toEqual([])
        })
    }
})
