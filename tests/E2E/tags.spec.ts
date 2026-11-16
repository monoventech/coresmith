import type { Page } from '@playwright/test'
import { blockingViolations, expect, gotoApplication, gotoServer, own, test, toast, useTheme } from './support/fixtures'

function editor(page: Page) {
    return page.getByTestId('tag-editor-panel')
}

function tagsRow(page: Page) {
    return page.getByTestId('app-tags-row')
}

test.describe('tags on an app', () => {
    test('are added by typing and from a suggestion, saved as you go, kept after a reload and removed again', async ({
        page
    }, testInfo) => {
        const app = own(testInfo, 'filed')

        await gotoApplication(page, 'bench-01', app)
        await tagsRow(page)
            .getByRole('button', { name: `Add tags to ${app}` })
            .click()
        await expect(editor(page)).toContainText(`Tags on ${app}`)
        await expect(editor(page)).toContainText('Changes save as you make them.')

        const input = editor(page).getByRole('textbox', { name: `Tags on ${app}` })

        await expect(input).toBeFocused()
        await input.fill('qa team')
        await input.press('Enter')
        await expect(toast(page)).toContainText('Saved.')
        await expect(editor(page).getByTestId('tag')).toHaveText(['qa team'])

        await editor(page).getByRole('button', { name: 'Add production' }).click()
        await expect(editor(page).getByTestId('tag')).toHaveText(['qa team', 'production'])
        await expect(input).toBeFocused()

        await page.keyboard.press('Escape')
        await expect(editor(page)).toHaveCount(0)
        await expect(tagsRow(page).getByTestId('app-tags')).toHaveText(/^\s*qa team\s*production\s*$/)
        await expect(tagsRow(page).getByTestId('tag-editor')).not.toHaveAttribute('aria-busy', 'true')

        await page.reload()
        await expect(tagsRow(page).getByTestId('app-tags')).toHaveText(/^\s*qa team\s*production\s*$/)

        await tagsRow(page)
            .getByRole('button', { name: new RegExp(`^Edit the tags on ${app}`) })
            .click()
        await editor(page).getByRole('button', { name: 'Remove qa team' }).click()
        await expect(editor(page).getByTestId('tag')).toHaveText(['production'])
        await editor(page)
            .getByRole('textbox', { name: `Tags on ${app}` })
            .press('Backspace')
        await expect(editor(page).getByTestId('tag')).toHaveCount(0)
        await page.keyboard.press('Escape')

        await expect(tagsRow(page).getByRole('button', { name: `Add tags to ${app}` })).toBeVisible()
        await expect(tagsRow(page).getByTestId('tag-editor')).not.toHaveAttribute('aria-busy', 'true')
        await page.reload()
        await expect(tagsRow(page).getByRole('button', { name: `Add tags to ${app}` })).toBeVisible()
    })

    test('a tag that starts with a symbol or runs long says what to fix and is not saved', async ({
        page
    }, testInfo) => {
        const app = own(testInfo, 'filed')

        await gotoApplication(page, 'bench-01', app)

        const before = await tagsRow(page).getByTestId('tag-editor').textContent()

        await tagsRow(page).getByTestId('tag-editor').click()

        const input = editor(page).getByRole('textbox', { name: `Tags on ${app}` })

        await input.fill('#ops')
        await input.press('Enter')
        await expect(editor(page).getByRole('alert')).toHaveText(
            'Start each tag with a letter or number, then use letters, numbers, spaces, dots, dashes or underscores.'
        )
        await input.fill('a tag far too long to keep')
        await input.press('Enter')
        await expect(editor(page).getByRole('alert')).toHaveText('Keep each tag to 24 characters or fewer.')

        await input.fill('')
        await page.keyboard.press('Escape')
        await expect(editor(page)).toHaveCount(0)
        await expect(tagsRow(page).getByTestId('tag-editor')).toHaveText(before ?? '')
    })

    test('Add an app takes tags split by commas and offers the ones already in use', async ({ page }) => {
        await gotoServer(page, 'bench-01')
        await page.getByRole('link', { name: 'Add an app' }).click()
        await expect(page.getByRole('heading', { level: 1, name: 'Add an app' })).toBeVisible()

        const input = page.getByRole('textbox', { name: 'Tags' })

        await input.pressSequentially('eu, staging,')
        await expect(page.getByTestId('tag-input').getByTestId('tag')).toHaveText(['eu', 'staging'])
        await expect(page.getByTestId('tag-suggestions')).toContainText('production')
        await expect(page.getByTestId('tag-suggestions')).not.toContainText('staging')

        await input.press('Backspace')
        await expect(page.getByTestId('tag-input').getByTestId('tag')).toHaveText(['eu'])
        await expect(page.locator('input[type=hidden][name="tags[]"]')).toHaveCount(1)
    })

    for (const theme of ['light', 'dark'] as const) {
        test(`the open tag editor is accessible in ${theme}`, async ({ page }, testInfo) => {
            const app = own(testInfo, 'filed')

            await useTheme(page, theme)
            await gotoApplication(page, 'bench-01', app)
            await tagsRow(page).getByTestId('tag-editor').click()
            await expect(editor(page)).toBeVisible()

            expect(await blockingViolations(page)).toEqual([])
        })
    }
})
