import { expect,  test as base } from '@playwright/test'
import type {Page} from '@playwright/test';

export const test = base

export const asMember = base.extend({
    storageState: 'tests/E2E/.auth/member.json',
})

export const asGuest = base.extend({
    storageState: { cookies: [], origins: [] },
})

export { expect }

export async function gotoServer(page: Page, name = 'web-1') {
    await page.goto('/servers')
    await page.getByRole('main').getByRole('link', { name }).click()
    await page.waitForURL(/\/servers\/[A-Za-z0-9]+$/)
}

export async function gotoApplications(page: Page, server = 'web-1') {
    await gotoServer(page, server)
    await page.getByRole('main').getByRole('link', { name: 'Applications' }).click()
    await page.waitForURL(/\/applications$/)
}

export async function gotoApplication(page: Page, name = 'Storefront') {
    await gotoApplications(page)
    await page.getByRole('main').getByRole('link', { name, exact: true }).click()
    await page.waitForURL(/\/applications\/[A-Za-z0-9]+$/)
}

export async function gotoDatabases(page: Page, server = 'web-1') {
    await gotoServer(page, server)
    await page.getByRole('main').getByRole('link', { name: 'Databases' }).click()
    await page.waitForURL(/\/databases$/)
}

export async function settled(page: Page, needle: string, timeout = 15_000) {
    await expect.poll(async () => (await page.content()).includes(needle), { timeout }).toBe(true)
}
