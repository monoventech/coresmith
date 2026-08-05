import { asMember, expect, settled, test } from './support/fixtures'

test.describe('Team management', () => {
    test('the roster lists everyone with their role', async ({ page }) => {
        await page.goto('/teams')

        await expect(page.getByRole('heading', { name: 'Monoven' })).toBeVisible()
        await expect(page.getByText('owner@coresmith.test')).toBeVisible()
        await expect(page.getByText('member@coresmith.test')).toBeVisible()
        await expect(page.getByText('(you)')).toBeVisible()
    })

    test('the team page is reachable from settings', async ({ page }) => {
        await page.goto('/settings/profile')
        await page.getByRole('link', { name: 'Team' }).first().click()

        await expect(page).toHaveURL(/teams/)
    })

    test('an owner can change a members role', async ({ page }) => {
        await page.goto('/teams')

        const row = page.locator('li').filter({ hasText: 'member@coresmith.test' })
        await row.getByRole('combobox').selectOption('admin')

        await settled(page, 'Role updated')
        await page.reload()
        await expect(row.getByRole('combobox')).toHaveValue('admin')

        await row.getByRole('combobox').selectOption('member')
        await settled(page, 'Role updated')
    })

    test('an owner can add and remove a member', async ({ page }) => {
        await page.goto('/teams')

        await page.getByLabel('Email address').fill('signout@coresmith.test')
        await page.getByRole('button', { name: 'Add member' }).click()

        await settled(page, 'signout@coresmith.test')

        const row = page.locator('li').filter({ hasText: 'signout@coresmith.test' })
        await row.getByRole('button', { name: 'Remove' }).click()

        await settled(page, 'Member removed')
    })

    test('adding an unknown address is refused', async ({ page }) => {
        await page.goto('/teams')

        await page.getByLabel('Email address').fill('nobody@coresmith.test')
        await page.getByRole('button', { name: 'Add member' }).click()

        await settled(page, 'selected email is invalid')
    })

    test('the last owner cannot be demoted', async ({ page }) => {
        await page.goto('/teams')

        const row = page.locator('li').filter({ hasText: 'owner@coresmith.test' })
        await row.getByRole('combobox').selectOption('member')

        await page.waitForTimeout(1500)
        await page.reload()

        await expect(row.getByRole('combobox')).toHaveValue('owner')
    })
})

asMember.describe('Team page as a member', () => {
    asMember('a member sees the roster but no controls', async ({ page }) => {
        await page.goto('/teams')

        await expect(page.getByText('owner@coresmith.test')).toBeVisible()
        await expect(page.getByRole('button', { name: 'Add member' })).toHaveCount(0)
        await expect(page.getByRole('button', { name: 'Remove' })).toHaveCount(0)
        await expect(page.getByRole('combobox')).toHaveCount(0)
    })
})
