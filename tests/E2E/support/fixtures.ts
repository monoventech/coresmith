import AxeBuilder from '@axe-core/playwright'
import type { Locator, Page, TestInfo } from '@playwright/test'
import { test as base, expect } from '@playwright/test'

export const test = base

export const asMember = base.extend({
    storageState: 'tests/E2E/.auth/member.json'
})

export const asGuest = base.extend({
    storageState: { cookies: [], origins: [] }
})

export { expect }

export const PASSWORD = 'password-for-e2e'

export const ORIGINAL_HOST_KEY = 'SHA256:AAAAoriginalkeyfingerprintAAAAAAAAAAAAAAAAAA'

export const PRESENTED_HOST_KEY = 'SHA256:BBBBimpostorkeyfingerprintBBBBBBBBBBBBBBBBBB'

const CODES: Record<string, string> = {
    chromium: 'chr',
    firefox: 'ffx',
    webkit: 'wbk',
    'mobile-chrome': 'mch',
    'mobile-safari': 'msf'
}

export function own(testInfo: TestInfo, prefix: string, separator = '-'): string {
    return `${prefix}${separator}${CODES[testInfo.project.name] ?? testInfo.project.name}`
}

export function isMobile(testInfo: TestInfo): boolean {
    return testInfo.project.name.startsWith('mobile-')
}

export function escape(value: string): string {
    return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
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

export async function backToMonoven(page: Page) {
    await page.goto('/monoven/dashboard')
    await expect(page.getByRole('heading', { level: 1, name: 'Your fleet' })).toBeVisible()
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

export async function blockingViolations(page: Page): Promise<string[]> {
    await page.waitForLoadState('load')
    await page.waitForLoadState('networkidle', { timeout: 5_000 }).catch(() => undefined)

    const results = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze()

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
