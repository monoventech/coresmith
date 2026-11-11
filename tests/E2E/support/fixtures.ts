import AxeBuilder from '@axe-core/playwright'
import type { Browser, Locator, Page, TestInfo } from '@playwright/test'
import { test as base, expect } from '@playwright/test'

export const test = base

export const asMember = base.extend({
    storageState: 'tests/E2E/.auth/member.json'
})

export const asNarrowed = base.extend({
    storageState: 'tests/E2E/.auth/narrowed.json'
})

export const asGuest = base.extend({
    storageState: { cookies: [], origins: [] }
})

export const asCloudMember = base.extend({
    storageState: 'tests/E2E/.auth/cloud-member.json'
})

export const asMover = base.extend({
    // eslint-disable-next-line no-empty-pattern
    storageState: async ({}, use, testInfo) => {
        await use(`tests/E2E/.auth/${own(testInfo, 'mover')}.json`)
    }
})

export const asCloudLeaver = base.extend({
    // eslint-disable-next-line no-empty-pattern
    storageState: async ({}, use, testInfo) => {
        await use(`tests/E2E/.auth/cloud-${own(testInfo, 'leaver')}.json`)
    }
})

export { expect }

export const PASSWORD = 'password-for-e2e'

export const ORIGINAL_HOST_KEY = 'SHA256:AAAAoriginalkeyfingerprintAAAAAAAAAAAAAAAAAA'

export const PRESENTED_HOST_KEY = 'SHA256:BBBBimpostorkeyfingerprintBBBBBBBBBBBBBBBBBB'

export const WAITING_INVITATION = 'e2eWaitingInvitation00000000000000000000'

export const EXPIRED_INVITATION = 'e2eExpiredInvitation00000000000000000000'

const CODES: Record<string, string> = {
    chromium: 'chr',
    firefox: 'ffx',
    webkit: 'wbk',
    'mobile-chrome': 'mch',
    'mobile-safari': 'msf'
}

export function own(testInfo: TestInfo, prefix: string, separator = '-'): string {
    const project = testInfo.project.name.replace(/^cloud-/, '')

    return `${prefix}${separator}${CODES[project] ?? project}`
}

export function isMobile(testInfo: TestInfo): boolean {
    return /(^|-)mobile-/.test(testInfo.project.name)
}

export async function signIn(page: Page, email: string, statePath: string) {
    await page.goto('/login')
    await page.getByLabel('Email address').fill(email)
    await page.getByLabel('Password', { exact: true }).fill(PASSWORD)
    await page.getByRole('button', { name: 'Sign in' }).click()

    await page.waitForURL('**/dashboard')
    await page.context().storageState({ path: statePath })
}

export function escape(value: string): string {
    return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
}

export async function pageData(page: Page): Promise<string> {
    await page.reload()

    const data = JSON.parse((await page.locator('script[data-page]').textContent()) ?? '{}')

    expect(Object.keys(data.props)).toContain('auth')

    return JSON.stringify(data)
}

export function main(page: Page): Locator {
    return page.getByTestId('panel-main')
}

export function toast(page: Page): Locator {
    return page.locator('[data-testid="toast"]:not([inert])')
}

export function dialog(page: Page): Locator {
    return page.getByRole('dialog')
}

export function tabs(page: Page, label: string): Locator {
    return page.getByRole('navigation', { name: label })
}

export function nav(page: Page): Locator {
    return page.getByRole('navigation', { name: 'Main' }).filter({ visible: true })
}

export const NARROW_PHONE = { width: 280, height: 400 }

export function tabOverhang(tab: Locator): Promise<number> {
    return tab.evaluate((element) => {
        const box = element.getBoundingClientRect()
        const strip = (element.parentElement as HTMLElement).getBoundingClientRect()

        return Math.max(box.right - strip.right, strip.left - box.left)
    })
}

export async function expectTabInView(tab: Locator) {
    await expect.poll(() => tabOverhang(tab)).toBeLessThanOrEqual(1)
}

export async function tabStaysInViewAsTabsWiden(page: Page, url: string, label: string, name: string) {
    await page.setViewportSize(NARROW_PHONE)
    await page.goto(url)

    const strip = tabs(page, label)
    const tab = strip.getByRole('link', { name, exact: true })
    const width = () => strip.evaluate((element) => element.scrollWidth)

    await expect(tab).toHaveAttribute('aria-current', 'page')
    await expectTabInView(tab)
    await expect(main(page)).toHaveJSProperty('scrollTop', 0)
    await strip.evaluate((element) => element.scrollIntoView({ block: 'nearest' }))

    const before = await width()
    const top = await main(page).evaluate((element) => element.scrollTop)

    await strip.evaluate((element) => {
        element.style.height = `${element.offsetHeight}px`
        element.querySelectorAll<HTMLElement>('a').forEach((link) => {
            link.style.letterSpacing = '0.1em'
        })
    })

    await expect.poll(width).toBeGreaterThan(before)
    await expectTabInView(tab)
    await expect(main(page)).toHaveJSProperty('scrollTop', top)
}

export async function tabComesIntoViewAsStripNarrows(page: Page, url: string, label: string, name: string) {
    await page.setViewportSize({ ...NARROW_PHONE, width: 1000 })
    await page.goto(url)

    const strip = tabs(page, label)
    const tab = strip.getByRole('link', { name, exact: true })

    await expect(tab).toHaveAttribute('aria-current', 'page')
    await page.setViewportSize(NARROW_PHONE)

    await expect.poll(() => strip.evaluate((element) => element.scrollLeft)).toBeGreaterThan(0)
    await expectTabInView(tab)
    await expect(main(page)).toHaveJSProperty('scrollTop', 0)
}

export async function useTheme(page: Page, theme: 'light' | 'dark') {
    const origin = new URL(test.info().project.use.baseURL ?? 'http://127.0.0.1:8123')

    await page.context().addCookies([{ name: 'appearance', value: theme, domain: origin.hostname, path: '/' }])
    await page.addInitScript((value) => window.localStorage.setItem('appearance', value), theme)
}

export async function gotoServer(page: Page, name: string) {
    await page.goto('/dashboard')
    await page.getByTestId('server-card').getByRole('link', { name, exact: true }).click()
    await page.waitForURL(/\/servers\/[A-Za-z0-9]+$/)
    await expect(page.getByRole('heading', { level: 1, name })).toBeVisible()
}

export async function gotoServerTab(page: Page, server: string, tab: 'Apps' | 'Databases' | 'Hardening' | 'Activity') {
    await gotoServer(page, server)

    if (tab !== 'Apps') {
        await tabs(page, 'Server sections').getByRole('link', { name: tab, exact: true }).click()
        await page.waitForURL(new RegExp(`/${tab.toLowerCase()}$`))
    }
}

export function applicationRow(page: Page, name: string): Locator {
    return page
        .getByTestId('applications-table')
        .getByRole('link')
        .filter({ has: page.getByText(name, { exact: true }) })
}

export function databaseRow(page: Page, name: string): Locator {
    return page
        .getByTestId('databases-table')
        .getByRole('link')
        .filter({ has: page.getByText(name, { exact: true }) })
}

export async function gotoApplication(page: Page, server: string, name: string) {
    await gotoServer(page, server)
    await applicationRow(page, name).click()
    await page.waitForURL(/\/applications\/[A-Za-z0-9]+$/)
    await expect(page.getByRole('heading', { level: 1, name, exact: true })).toBeVisible()
}

export async function gotoDatabase(page: Page, server: string, name: string) {
    await gotoServerTab(page, server, 'Databases')
    await databaseRow(page, name).click()
    await page.waitForURL(/\/databases\/[A-Za-z0-9]+$/)
    await expect(page.getByRole('heading', { level: 1, name, exact: true })).toBeVisible()
}

export async function gotoSettings(
    page: Page,
    tab: 'General' | 'Team' | 'Git' | 'Notifications' | 'API tokens' | 'Billing'
) {
    await page.goto('/dashboard')
    await nav(page).getByRole('link', { name: 'Settings' }).click()
    await page.waitForURL(/\/settings$/)

    if (tab !== 'General') {
        await tabs(page, 'Settings').getByRole('link', { name: tab, exact: true }).click()
        await expect(tabs(page, 'Settings').getByRole('link', { name: tab, exact: true })).toHaveAttribute(
            'aria-current',
            'page'
        )
    }
}

export async function captureClipboard(page: Page) {
    await page.addInitScript(() => {
        Object.defineProperty(navigator, 'clipboard', {
            configurable: true,
            value: { writeText: async (text: string) => void Object.assign(window, { copied: text }) }
        })
    })
}

export async function refuseClipboard(page: Page) {
    await page.addInitScript(() => {
        Object.defineProperty(navigator, 'clipboard', {
            configurable: true,
            value: { writeText: () => Promise.reject(new DOMException('Clipboard refused', 'NotAllowedError')) }
        })
    })
}

export function cloudInvitation(testInfo: TestInfo): string {
    return own(testInfo, 'e2eCloudInvite', '').padEnd(40, '0')
}

export async function clipboardText(page: Page): Promise<string> {
    return page.evaluate(() => String(Reflect.get(window, 'copied') ?? ''))
}

export async function guestPage(browser: Browser, testInfo: TestInfo): Promise<Page> {
    const { baseURL, viewport, userAgent, deviceScaleFactor, isMobile, hasTouch } = testInfo.project.use
    const context = await browser.newContext({
        baseURL,
        viewport,
        userAgent,
        deviceScaleFactor,
        ...(isMobile ? { isMobile, hasTouch } : {}),
        storageState: { cookies: [], origins: [] }
    })

    return context.newPage()
}

export async function backToMonoven(page: Page) {
    await page.goto('/monoven/dashboard')
    await expect(page.getByRole('heading', { level: 1, name: 'Your fleet' })).toBeVisible()
}

export async function openSetupWith(page: Page, slug: string, github: Record<string, unknown>, delay = 0) {
    await page.route(`**/${slug}/setup**`, async (route) => {
        if (route.request().headers()['x-inertia'] === undefined) {
            await route.continue()

            return
        }

        const response = await route.fetch()
        const body = await response.json()

        body.props.github = { ...body.props.github, ...github }
        await new Promise((resolve) => setTimeout(resolve, delay))
        await route.fulfill({ response, json: body })
    })
    await page.goto(`/${slug}/setup`)
    await page
        .getByRole('navigation', { name: 'Setup steps' })
        .getByRole('button', { name: /Connect GitHub/ })
        .click()
    await page.waitForURL(/[?&]step=0$/)
}

export async function openSecurity(page: Page) {
    await page.goto('/settings/security')

    if (new URL(page.url()).pathname === '/user/confirm-password') {
        await page.getByLabel('Password', { exact: true }).fill(PASSWORD)
        await page.getByRole('button', { name: 'Confirm' }).click()
        await page.waitForURL(/\/settings\/security$/)
    }

    await expect(page.getByTestId('password-card')).toBeVisible()
}

type InertiaBody = { props: Record<string, unknown>; flash?: Record<string, unknown> }

export async function withInertia(page: Page, url: RegExp, change: (body: InertiaBody) => void) {
    await page.route(url, async (route) => {
        if (route.request().headers()['x-inertia'] === undefined) {
            await route.continue()

            return
        }

        const response = await route.fetch()
        const body = (await response.json()) as InertiaBody

        change(body)
        await route.fulfill({ response, json: body })
    })
}

export async function withProps(page: Page, url: RegExp, change: (props: Record<string, unknown>) => void) {
    await withInertia(page, url, (body) => change(body.props))
}

export async function blockingViolations(page: Page): Promise<string[]> {
    await page.waitForLoadState('load')
    await page.waitForLoadState('networkidle', { timeout: 5_000 }).catch(() => undefined)

    const grain = await page.addStyleTag({ content: '.grain::after { content: none !important; }' })
    const results = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze()
    await grain.evaluate((style) => style.remove())

    return results.violations
        .filter((violation) => ['serious', 'critical'].includes(violation.impact ?? ''))
        .flatMap((violation) =>
            violation.nodes.map((node) => `${violation.id}: ${node.target.join(' ')} ${node.failureSummary ?? ''}`)
        )
}

export async function noSidewaysScroll(page: Page) {
    const overflow = await page.evaluate(() => {
        const main = document.querySelector('main')
        const page = document.documentElement.scrollWidth - document.documentElement.clientWidth
        const inner = main ? main.scrollWidth - main.clientWidth : 0

        return Math.max(page, inner)
    })

    expect(overflow).toBeLessThanOrEqual(1)
}
