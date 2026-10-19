import type { Locator, Page } from '@playwright/test'
import { asMember, backToMonoven, dialog, expect, gotoSettings, own, test, toast } from './support/fixtures'

function member(page: Page, email: string) {
    return page.getByTestId('member-row').filter({ hasText: email })
}

async function removeMember(page: Page, email: string, name: string) {
    await member(page, email)
        .getByRole('button', { name: `Remove ${name}` })
        .click()
    await expect(dialog(page)).toContainText(`Remove ${name}?`)
    await dialog(page).getByRole('button', { name: 'Remove from the team' }).click()

    await expect(toast(page)).toContainText('Member removed.')
    await expect(member(page, email)).toHaveCount(0)
}

async function offerOwner(select: Locator) {
    await select.evaluate((element: HTMLSelectElement) => element.add(new Option('Owner', 'owner')))
}

test.describe('the team', () => {
    test('everyone is listed with their role and whether they use two-factor', async ({ page }) => {
        await gotoSettings(page, 'Team')

        const card = page.getByTestId('team-card')

        await expect(card.getByRole('heading', { name: 'Monoven Ltd' })).toBeVisible()
        await expect(member(page, 'owner@coresmith.test')).toContainText('(you)')
        await expect(member(page, 'owner@coresmith.test')).toContainText('Owner')
        await expect(member(page, 'jonas@coresmith.test')).toContainText('2FA')
        await expect(member(page, 'member@coresmith.test')).toContainText('No 2FA')
        await expect(card).toContainText('Deployers can deploy and roll back.')
    })

    test('your own row cannot change your own role', async ({ page }) => {
        await gotoSettings(page, 'Team')

        await expect(member(page, 'owner@coresmith.test').getByRole('combobox')).toHaveCount(0)
        await expect(member(page, 'owner@coresmith.test').getByRole('button')).toHaveCount(0)
    })

    test('a role is changed and kept', async ({ page }) => {
        await gotoSettings(page, 'Team')

        const role = member(page, 'rowan@coresmith.test').getByRole('combobox', { name: 'Role for Rowan Hale' })

        await role.selectOption('admin')
        await expect(toast(page)).toContainText('Role updated.')

        await page.reload()
        await expect(member(page, 'rowan@coresmith.test').getByRole('combobox')).toHaveValue('admin')

        await member(page, 'rowan@coresmith.test').getByRole('combobox').selectOption('member')
        await expect(toast(page)).toContainText('Role updated.')
    })

    test('someone with an account is invited and then removed', async ({ page }, testInfo) => {
        const email = `${own(testInfo, 'invitee')}@coresmith.test`
        const name = `Invitee ${own(testInfo, 'invitee').split('-')[1]}`

        await gotoSettings(page, 'Team')
        await expect(member(page, 'owner@coresmith.test')).toBeVisible()

        if ((await member(page, email).count()) > 0) {
            await removeMember(page, email, name)
        }

        await page.getByRole('button', { name: 'Invite someone' }).click()
        await dialog(page).getByLabel('Email address').fill(email)
        await dialog(page).getByRole('button', { name: 'Add to the team' }).click()

        await expect(toast(page)).toContainText('Member added.')
        await expect(member(page, email)).toBeVisible()

        await removeMember(page, email, name)
    })

    test('an address without an account is refused in the dialog', async ({ page }) => {
        await gotoSettings(page, 'Team')

        await page.getByRole('button', { name: 'Invite someone' }).click()
        await dialog(page).getByLabel('Email address').fill('nobody@coresmith.test')
        await dialog(page).getByRole('button', { name: 'Add to the team' }).click()

        await expect(dialog(page).getByLabel('Email address')).toHaveAttribute('aria-invalid', 'true')
    })

    test('someone already on the team is refused in the dialog', async ({ page }) => {
        await gotoSettings(page, 'Team')

        await page.getByRole('button', { name: 'Invite someone' }).click()
        await dialog(page).getByLabel('Email address').fill('jonas@coresmith.test')
        await dialog(page).getByRole('button', { name: 'Add to the team' }).click()

        await expect(dialog(page).getByLabel('Email address')).toHaveAttribute('aria-invalid', 'true')
        await expect(dialog(page)).toContainText('That person is already on this team.')
        await expect(page.locator('iframe')).toHaveCount(0)
        await expect(member(page, 'jonas@coresmith.test')).toHaveCount(1)
    })

    test('the remove dialog keeps the name until it has closed', async ({ page }) => {
        await gotoSettings(page, 'Team')

        const titles = await page.evaluateHandle(() => {
            const seen = new Set<string>()
            const record = () =>
                document
                    .querySelectorAll('[data-slot="dialog-title"]')
                    .forEach((title) => seen.add(title.textContent ?? ''))

            new MutationObserver(record).observe(document.body, { childList: true, subtree: true, characterData: true })

            return seen
        })

        await member(page, 'rowan@coresmith.test').getByRole('button', { name: 'Remove Rowan Hale' }).click()
        await expect(dialog(page)).toContainText('Remove Rowan Hale?')
        await dialog(page).getByRole('button', { name: 'Keep them' }).click()
        await expect(dialog(page)).toHaveCount(0)

        expect(await titles.evaluate((seen) => [...seen])).toEqual(['Remove Rowan Hale?'])
        await expect(member(page, 'rowan@coresmith.test')).toHaveCount(1)
    })
})

test.describe('an admin on a team they do not own', () => {
    const owner = { name: 'Elin Larsen', email: 'elin@coresmith.test' }
    const deployer = { name: 'Tomas Novak', email: 'tomas@coresmith.test' }

    test.beforeEach(async ({ page }) => {
        await page.goto('/acme/settings/team')
        await expect(member(page, owner.email)).toBeVisible()
    })

    test.afterEach(async ({ page }) => {
        await backToMonoven(page)
    })

    test('an owner is listed as text, with no role select and no remove button', async ({ page }) => {
        const row = member(page, owner.email)

        await expect(row).toContainText('Owner')
        await expect(row.getByRole('combobox')).toHaveCount(0)
        await expect(row.getByRole('button')).toHaveCount(0)
    })

    test('a deployer keeps a role select and a remove button', async ({ page }) => {
        const row = member(page, deployer.email)

        await expect(row.getByRole('combobox', { name: `Role for ${deployer.name}` })).toHaveValue('member')
        await expect(row.getByRole('button', { name: `Remove ${deployer.name}` })).toBeVisible()
    })

    test('a page that still offers Owner cannot make someone an owner', async ({ page }) => {
        const role = member(page, deployer.email).getByRole('combobox', { name: `Role for ${deployer.name}` })

        await expect(role).toHaveValue('member')
        await offerOwner(role)
        await role.selectOption('owner')

        await expect(toast(page)).toContainText('Only an owner can grant the owner role.')
        await expect(toast(page)).toContainText('Ask an owner of this team to do it.')
        await expect(role).toHaveValue('member')
    })

    test('a page that still offers Owner cannot invite someone as an owner', async ({ page }) => {
        await page.getByRole('button', { name: 'Invite someone' }).click()
        await dialog(page).getByLabel('Email address').fill('rowan@coresmith.test')
        await offerOwner(dialog(page).getByLabel('Role', { exact: true }))
        await dialog(page).getByLabel('Role', { exact: true }).selectOption('owner')
        await dialog(page).getByRole('button', { name: 'Add to the team' }).click()

        await expect(dialog(page)).toContainText(
            'Only an owner can grant the owner role. Ask an owner of this team to do it.'
        )
        await expect(member(page, 'rowan@coresmith.test')).toHaveCount(0)
    })
})

asMember.describe('the team as a member', () => {
    asMember('a member sees the roster but no controls', async ({ page }) => {
        await gotoSettings(page, 'Team')

        await expect(member(page, 'owner@coresmith.test')).toBeVisible()
        await expect(page.getByRole('button', { name: 'Invite someone' })).toHaveCount(0)
        await expect(page.getByTestId('team-card').getByRole('combobox')).toHaveCount(0)
        await expect(page.getByTestId('team-card').getByRole('button', { name: /^Remove / })).toHaveCount(0)
    })
})
