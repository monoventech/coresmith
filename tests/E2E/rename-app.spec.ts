import { dialog, expect, gotoApplication, isMobile, main, own, test, toast } from './support/fixtures'

test.use({ reducedMotion: 'reduce' })

test('an app is renamed from its page, and nothing on the server changes', async ({ page }, testInfo) => {
    test.skip(isMobile(testInfo), "Phones keep the prototype's compact app page, which leaves out Rename")

    const app = own(testInfo, 'named')
    const renamed = `${app}-renamed`

    await gotoApplication(page, 'bench-01', app)
    await main(page).getByRole('button', { name: 'Rename', exact: true }).click()

    await expect(dialog(page).getByRole('heading', { name: 'Rename app' })).toBeVisible()
    await expect(dialog(page)).toContainText('The name is only for Coresmith. Nothing on the server changes.')
    await expect(dialog(page).getByLabel('App name')).toHaveValue(app)

    await dialog(page).getByLabel('App name').fill('')
    await dialog(page).getByRole('button', { name: 'Save' }).click()
    await expect(dialog(page).getByLabel('App name')).toBeVisible()

    await dialog(page).getByLabel('App name').fill(renamed)
    await dialog(page).getByRole('button', { name: 'Save' }).click()

    await expect(toast(page)).toContainText('Saved.')
    await expect(dialog(page)).toHaveCount(0)
    await expect(page.getByRole('heading', { level: 1, name: renamed })).toBeVisible()
    await expect(page).toHaveTitle(new RegExp(`^${renamed}`))

    await main(page).getByRole('button', { name: 'Rename', exact: true }).click()
    await dialog(page).getByLabel('App name').fill(app)
    await dialog(page).getByRole('button', { name: 'Save' }).click()

    await expect(page.getByRole('heading', { level: 1, name: app, exact: true })).toBeVisible()
})
