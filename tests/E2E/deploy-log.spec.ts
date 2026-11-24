import type { Page } from '@playwright/test'
import { readFile } from 'node:fs/promises'
import { answerLog, byteLength, streamDeploys } from './support/deploy-stream'
import { blockingViolations, expect, gotoApplication, isMobile, test, useTheme } from './support/fixtures'

const BUILD = Array.from({ length: 30 }, (_, index) => `transforming (${index}) src/routes/item-${index}.svelte`)

function output(page: Page) {
    return page.getByLabel('Deployment output')
}

async function openLiveDeploy(page: Page) {
    await page.goto('/dashboard')
    await page.getByTestId('live-deploy').click()
    await expect(page.getByTestId('phase-rail')).toBeVisible()
}

async function openFailedDeploy(page: Page) {
    await gotoApplication(page, 'forge-01', 'storefront')
    await page.getByTestId('failed-banner').getByRole('link', { name: 'Read the log' }).click()
    await expect(page.getByTestId('deployment-status')).toContainText('Failed')
}

async function recordLengths(page: Page) {
    await page.evaluate(() => {
        const log = document.querySelector('[data-testid="deployment-log"]') as HTMLElement
        const lengths: number[] = []

        Object.assign(window, { lengths })
        new MutationObserver(() => lengths.push(log.textContent?.length ?? 0)).observe(log, {
            subtree: true,
            childList: true,
            characterData: true
        })
    })
}

async function distinctLengths(page: Page): Promise<number> {
    return page.evaluate(() => new Set((window as unknown as { lengths: number[] }).lengths).size)
}

test.describe('a streamed deploy log', () => {
    test('new output flows in over a moment instead of landing at once, and catches up within a second', async ({
        page
    }) => {
        const stream = await streamDeploys(page)

        await openLiveDeploy(page)
        await stream.ready()
        await recordLengths(page)
        await stream.append(`\n${BUILD.join('\n')}`)

        await expect(output(page)).toContainText('transforming (29) src/routes/item-29.svelte', { timeout: 1_500 })
        expect(await distinctLengths(page)).toBeGreaterThan(3)
    })

    test('with reduced motion new output appears at once', async ({ page }) => {
        const stream = await streamDeploys(page)

        await page.emulateMedia({ reducedMotion: 'reduce' })
        await openLiveDeploy(page)
        await stream.ready()
        await recordLengths(page)
        await stream.append(`\n${BUILD.join('\n')}`)

        await expect(output(page)).toContainText('transforming (29) src/routes/item-29.svelte')
        expect(await distinctLengths(page)).toBeLessThanOrEqual(2)
    })

    test('a piece that arrives early waits for the one before it', async ({ page }) => {
        const stream = await streamDeploys(page)
        const first = '\n[12:00:00] build: the first piece'
        const second = '\n[12:00:01] build: the second piece'

        await openLiveDeploy(page)
        await stream.ready()

        const start = stream.bytes()

        await stream.log(start + byteLength(first), second)
        await stream.log(start, first)

        await expect(output(page)).toContainText('build: the second piece')

        const text = (await output(page).textContent()) ?? ''

        expect(text.indexOf('build: the first piece')).toBeLessThan(text.indexOf('build: the second piece'))
    })

    test('a piece lost on the way is fetched from the log', async ({ page }) => {
        const stream = await streamDeploys(page)
        const lost = '\n[12:00:02] build: lost on the way'
        const later = '\n[12:00:03] build: sent after it'
        let gap = -1

        await answerLog(page, (from) => (from === gap ? lost : null))
        await openLiveDeploy(page)
        await stream.ready()

        gap = stream.bytes()
        await stream.log(gap + byteLength(lost), later)

        await expect(output(page)).toContainText('build: lost on the way')
        await expect(output(page)).toContainText('build: sent after it')
    })

    test('reading earlier output stops following, and New output brings you back to the end', async ({ page }) => {
        const stream = await streamDeploys(page)

        await openLiveDeploy(page)
        await stream.append(`\n${BUILD.join('\n')}`)
        await expect(output(page)).toContainText('transforming (29)')

        await output(page).evaluate((element) => (element.scrollTop = 0))
        await expect(output(page)).toHaveAttribute('data-following', 'false')

        await stream.append('\n[12:00:04] build: more output')

        const jump = page.getByTestId('log-new-output')

        await expect(jump).toBeVisible()
        await jump.click()

        await expect(output(page)).toHaveAttribute('data-following', 'true')
        await expect(jump).toBeHidden()
        expect(
            await output(page).evaluate((element) => element.scrollHeight - element.scrollTop - element.clientHeight)
        ).toBeLessThan(24)
    })

    test('without a socket the page follows the log every second', async ({ page }) => {
        const polled = '\n[12:00:05] build: read without a socket'
        let served = false

        await answerLog(page, () => {
            if (served) {
                return null
            }

            served = true

            return polled
        })
        await openLiveDeploy(page)

        await expect(page.getByTestId('log-transport')).toHaveText('Following every second')
        await expect(output(page)).toContainText('build: read without a socket', { timeout: 3_000 })
    })
})

test.describe('the log tools', () => {
    test('Find marks every match and moves between them', async ({ page }) => {
        const stream = await streamDeploys(page)

        await openLiveDeploy(page)
        await stream.append('\n$ npm run build\ncompiled zebra-token one\ncompiled zebra-token two')
        await expect(output(page)).toContainText('compiled zebra-token two')

        const find = page.getByLabel('Find in output')
        const count = page.getByTestId('log-find-count')

        await find.fill('zebra-token')
        await expect(count).toHaveText('1 of 2')
        await expect(output(page).locator('[data-mark]')).toHaveCount(2)
        await expect(output(page).locator('[data-mark="current"]')).toHaveText('zebra-token')

        await find.press('Enter')
        await expect(count).toHaveText('2 of 2')
        await find.press('Enter')
        await expect(count).toHaveText('1 of 2')
        await find.press('Shift+Enter')
        await expect(count).toHaveText('2 of 2')

        await find.press('Escape')
        await expect(find).toHaveValue('')
        await expect(output(page).locator('[data-mark]')).toHaveCount(0)
    })

    test('Find says so when nothing matches', async ({ page }) => {
        await openFailedDeploy(page)
        await page.getByLabel('Find in output').fill('no-such-text-anywhere')

        await expect(page.getByTestId('log-find-count')).toHaveText('No matches')
        await expect(page.getByRole('button', { name: 'Next match' })).toBeDisabled()
    })

    test('Control or Command F inside the log goes to Find', async ({ page }) => {
        await openFailedDeploy(page)
        await output(page).focus()
        await page.keyboard.press('ControlOrMeta+f')

        await expect(page.getByLabel('Find in output')).toBeFocused()
    })

    test('lines can be left unwrapped to scroll sideways', async ({ page }, testInfo) => {
        await openFailedDeploy(page)

        const wrap = page.getByTestId('log-wrap')

        if (isMobile(testInfo)) {
            await expect(wrap).toBeHidden()

            return
        }

        await expect(wrap).toHaveAttribute('aria-pressed', 'true')
        await expect(output(page).locator('[data-line]').first()).toHaveCSS('white-space', 'pre-wrap')

        await wrap.click()

        await expect(wrap).toHaveAttribute('aria-pressed', 'false')
        await expect(output(page).locator('[data-line]').first()).toHaveCSS('white-space', 'pre')
    })

    test('on a phone lines wrap even when Wrap lines was turned off on a wider screen', async ({ page }, testInfo) => {
        test.skip(!isMobile(testInfo), 'From 640px up Wrap lines decides; phones always wrap and hide the button')

        await page.addInitScript(() => window.localStorage.setItem('log-wrap', 'off'))
        await openFailedDeploy(page)

        await expect(output(page).locator('[data-line]').first()).toHaveCSS('white-space', 'pre-wrap')
    })

    test('First error jumps to the first error and marks it', async ({ page }) => {
        await openFailedDeploy(page)
        await output(page).evaluate((element) => (element.scrollTop = element.scrollHeight))
        await page.getByTestId('log-first-error').click()

        const first = output(page).locator('[data-kind="error"]').first()

        await expect(first).toContainText('Cannot resolve "./lib/pricing"')
        await expect(first).toHaveClass(/log-line-flash/)
        await expect(first).toBeInViewport()
    })

    test('First error is off when the log has no errors', async ({ page }) => {
        await openLiveDeploy(page)

        await expect(page.getByTestId('log-first-error')).toBeDisabled()
    })

    test('commands, phases and errors read differently', async ({ page }) => {
        await openFailedDeploy(page)

        await expect(output(page).locator('[data-kind="command"]').filter({ hasText: '$ bun run build' })).toHaveCount(
            1
        )
        await expect(
            output(page).locator('[data-kind="phase"]').filter({ hasText: 'build: building the release' })
        ).toHaveCount(1)
        await expect(
            output(page)
                .locator('[data-kind="error"]')
                .filter({ hasText: 'deploy failed. the current release is untouched.' })
        ).toHaveCount(1)
    })

    test('Copy puts the whole log on the clipboard', async ({ page }) => {
        await page.addInitScript(() => {
            Object.defineProperty(navigator, 'clipboard', {
                configurable: true,
                value: { writeText: async (text: string) => Object.assign(window, { copied: text }) }
            })
        })
        await openFailedDeploy(page)
        await page.getByTestId('log-copy').click()

        await expect(page.getByTestId('log-copy')).toHaveText('Copied')

        const copied = await page.evaluate(() => (window as unknown as { copied: string }).copied)

        expect(copied).toContain('build: building the release')
        expect(copied.trimEnd().endsWith('deploy failed. the current release is untouched.')).toBe(true)
    })

    test('Download saves the whole log, named after the app and release', async ({ page }, testInfo) => {
        await openFailedDeploy(page)

        const download = page.getByTestId('log-download')

        if (isMobile(testInfo)) {
            await expect(download).toBeHidden()

            return
        }

        const [file] = await Promise.all([page.waitForEvent('download'), download.click()])
        const text = await readFile(await file.path(), 'utf8')

        expect(file.suggestedFilename()).toMatch(/^storefront-.+\.log$/)
        expect(text).toContain('error: Cannot resolve "./lib/pricing" from src/routes/cart.svelte')
        expect(text.trimEnd().endsWith('deploy failed. the current release is untouched.')).toBe(true)
    })
})

test.describe('the streamed log, for everyone', () => {
    for (const theme of ['light', 'dark'] as const) {
        test(`a running log with a search has no serious or critical accessibility violations in ${theme}`, async ({
            page
        }) => {
            await useTheme(page, theme)

            const stream = await streamDeploys(page)

            await openLiveDeploy(page)
            await stream.append(
                '\nnpm warn deprecated inflight@1.0.6: This module is not supported\nerror: Cannot resolve "./lib/pricing"\n✓ built in 3.2s'
            )
            await page.getByLabel('Find in output').fill('pricing')
            await expect(page.getByTestId('log-find-count')).toHaveText('1 of 1')

            expect(await blockingViolations(page)).toEqual([])
        })

        test(`a finished log has no serious or critical accessibility violations in ${theme}`, async ({ page }) => {
            await useTheme(page, theme)
            await openFailedDeploy(page)
            await page.getByTestId('log-first-error').click()

            expect(await blockingViolations(page)).toEqual([])
        })
    }
})
