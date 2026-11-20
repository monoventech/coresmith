import type { Page } from '@playwright/test'
import { blockingViolations, expect, gotoServer, isMobile, own, test, toast, useTheme } from './support/fixtures'

function editor(page: Page) {
    return page.getByTestId('tag-editor-panel')
}

function tagsRow(page: Page) {
    return page.getByTestId('server-tags-row')
}

test.describe('tags on a server', () => {
    test('are added from its page with a suggestion, show in the explorer and on Fleet, and come off again', async ({
        page
    }, testInfo) => {
        const server = own(testInfo, 'tags')

        await gotoServer(page, server)
        await tagsRow(page)
            .getByRole('button', { name: `Add tags to ${server}` })
            .click()
        await expect(editor(page)).toContainText(`Tags on ${server}`)
        await expect(editor(page)).toContainText('Used on other servers')

        const input = editor(page).getByRole('textbox', { name: `Tags on ${server}` })

        await input.fill('qa team')
        await input.press('Enter')
        await expect(toast(page)).toContainText('Saved.')
        await editor(page).getByRole('button', { name: 'Add production' }).click()
        await expect(editor(page).getByTestId('tag')).toHaveText(['qa team', 'production'])
        await page.keyboard.press('Escape')
        await expect(tagsRow(page).getByTestId('app-tags')).toHaveText(/^\s*qa team\s*production\s*$/)
        await expect(tagsRow(page).getByTestId('tag-editor')).not.toHaveAttribute('aria-busy', 'true')

        if (!isMobile(testInfo)) {
            await expect(
                page
                    .getByRole('complementary', { name: 'Explorer' })
                    .getByRole('link', { name: `${server}, qa team, production, Healthy` })
            ).toBeVisible()
        }

        await page.goto('/dashboard')
        await expect(
            page
                .getByTestId('server-card')
                .filter({ has: page.getByRole('link', { name: server, exact: true }) })
                .getByTestId('app-tags')
        ).toHaveText(/^\s*qa team\s*production\s*$/)

        await gotoServer(page, server)
        await tagsRow(page)
            .getByRole('button', { name: new RegExp(`^Edit the tags on ${server}`) })
            .click()
        await editor(page).getByRole('button', { name: 'Remove qa team' }).click()
        await editor(page).getByRole('button', { name: 'Remove production' }).click()
        await expect(editor(page).getByTestId('tag')).toHaveCount(0)
        await page.keyboard.press('Escape')
        await expect(tagsRow(page).getByRole('button', { name: `Add tags to ${server}` })).toBeVisible()
        await expect(tagsRow(page).getByTestId('tag-editor')).not.toHaveAttribute('aria-busy', 'true')
    })

    for (const theme of ['light', 'dark'] as const) {
        test(`the server page with its tag editor open is accessible in ${theme}`, async ({ page }, testInfo) => {
            await useTheme(page, theme)
            await gotoServer(page, own(testInfo, 'tags'))
            await tagsRow(page).getByTestId('tag-editor').click()
            await expect(editor(page)).toBeVisible()

            expect(await blockingViolations(page)).toEqual([])
        })
    }
})
