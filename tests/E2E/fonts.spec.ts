import type { Page } from '@playwright/test'
import { asGuest, expect } from './support/fixtures'

const FACES = [
    'Bespoke Slab 300 800 italic',
    'Bespoke Slab 300 800 normal',
    'Hanken Grotesk 400 normal',
    'Hanken Grotesk 500 normal',
    'Hanken Grotesk 600 normal',
    'JetBrains Mono 400 normal',
    'JetBrains Mono 500 normal'
]

function fontFaces(page: Page): Promise<{ name: string; status: string }[]> {
    return page.evaluate(async () => {
        await document.fonts.ready

        return [...document.fonts].map((face) => ({
            name: `${face.family.replaceAll('"', '')} ${face.weight} ${face.style}`,
            status: face.status
        }))
    })
}

function hankenFacesInUse(page: Page): Promise<string[]> {
    return page.evaluate(() => {
        const inUse = new Set<string>()

        for (const element of document.querySelectorAll('body *')) {
            const style = getComputedStyle(element)
            const hasText = [...element.childNodes].some(
                (node) => node.nodeType === Node.TEXT_NODE && node.textContent?.trim()
            )

            if (hasText && element.getClientRects().length > 0 && /^"?Hanken Grotesk/.test(style.fontFamily)) {
                inUse.add(`Hanken Grotesk ${style.fontWeight} ${style.fontStyle}`)
            }
        }

        return [...inUse]
    })
}

asGuest.describe('web fonts on a cold visit', () => {
    asGuest.beforeEach(async ({ page }) => {
        await page.goto('/login')
        await expect(page.getByRole('button', { name: 'Sign in' })).toBeVisible()
    })

    asGuest('each font face appears once and every face is preloaded', async ({ page }) => {
        const faces = await fontFaces(page)

        expect(faces.map((face) => face.name).toSorted()).toEqual(FACES)
        await expect(page.locator('link[rel="preload"][as="font"]')).toHaveCount(5)
    })

    asGuest('the Hanken Grotesk faces the page uses reach loaded', async ({ page }) => {
        const inUse = await hankenFacesInUse(page)

        expect(inUse).not.toEqual([])

        await expect
            .poll(async () => {
                const faces = await fontFaces(page)

                return inUse.map((name) => faces.filter((face) => face.name === name).map((face) => face.status))
            })
            .toEqual(inUse.map(() => ['loaded']))
    })
})

asGuest('a cold visit asks for no .woff file', async ({ page }) => {
    const woff: string[] = []

    page.on('request', (request) => {
        if (new URL(request.url()).pathname.endsWith('.woff')) {
            woff.push(new URL(request.url()).pathname)
        }
    })

    await page.goto('/login')
    await expect(page.getByRole('button', { name: 'Sign in' })).toBeVisible()
    await fontFaces(page)

    expect(woff).toEqual([])
})
