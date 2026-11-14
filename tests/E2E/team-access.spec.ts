import type { Page } from '@playwright/test'
import {
    asNarrowed,
    blockingViolations,
    dialog,
    expect,
    gotoSettings,
    own,
    test,
    toast,
    useTheme
} from './support/fixtures'

function member(page: Page, email: string) {
    return page.getByTestId('member-row').filter({ hasText: email })
}

test.describe('what each member sees', () => {
    test('an owner narrows a member, ticks a server and an app, holds back one app on the server, and the row says so', async ({
        page
    }, testInfo) => {
        const email = `${own(testInfo, 'narrow')}@coresmith.test`
        const name = `Narrow ${own(testInfo, 'narrow').split('-')[1]}`

        await gotoSettings(page, 'Team')
        await expect(member(page, email).getByTestId('member-access')).toHaveText('Sees everything')

        await member(page, email)
            .getByRole('button', { name: `Access for ${name}` })
            .click()
        await expect(dialog(page)).toContainText(`What ${name} can see`)

        const everything = dialog(page).getByRole('switch', { name: 'Everything in Monoven Ltd' })

        await expect(everything).toBeChecked()
        await everything.click()
        await expect(dialog(page)).toContainText(
            `${name} sees only what you tick. A project brings every environment in it, and a server every app and database on it, apart from any you untick.`
        )

        await dialog(page).getByRole('checkbox', { name: 'forge-02', exact: true }).click()
        await dialog(page)
            .getByRole('button', { name: / on forge-02$/ })
            .click()

        const held = dialog(page).getByRole('checkbox', { name: 'atlas-staging', exact: true })

        await expect(held).toBeChecked()
        await expect(held).toBeEnabled()
        await held.click()
        await expect(held).not.toBeChecked()
        await dialog(page)
            .getByRole('button', { name: / on anvil-01$/ })
            .click()
        await dialog(page).getByRole('checkbox', { name: 'warehouse', exact: true }).click()
        await dialog(page).getByRole('button', { name: 'Save' }).click()

        await expect(toast(page)).toContainText('Saved.')
        await expect(dialog(page)).toHaveCount(0)
        await expect(member(page, email).getByTestId('member-access')).toHaveText('1 server, 1 app, 1 held back')

        await page.reload()
        await expect(member(page, email).getByTestId('member-access')).toHaveText('1 server, 1 app, 1 held back')

        await member(page, email)
            .getByRole('button', { name: `Access for ${name}` })
            .click()
        await expect(dialog(page).getByRole('checkbox', { name: 'forge-02', exact: true })).toBeChecked()
        await expect(dialog(page).getByRole('checkbox', { name: 'atlas-staging', exact: true })).not.toBeChecked()
        await dialog(page)
            .getByRole('button', { name: /environments? in Storefront$/ })
            .click()
        await dialog(page).getByRole('checkbox', { name: 'Staging', exact: true }).click()
        await dialog(page).getByRole('button', { name: 'Save' }).click()

        await expect(toast(page)).toContainText('Saved.')
        await expect(member(page, email).getByTestId('member-access')).toHaveText(
            '1 environment, 1 server, 1 app, 1 held back'
        )
    })

    test('an admin who signs in to a shared server over SSH keeps everything on it, and the dialog says why', async ({
        page
    }) => {
        await gotoSettings(page, 'Team')
        await member(page, 'narrowed@coresmith.test').getByRole('button', { name: 'Access for Noor Haddad' }).click()
        await expect(dialog(page).getByRole('checkbox', { name: 'forge-01', exact: true })).toBeChecked()
        await expect(dialog(page).getByTestId('share-signs-in')).toHaveCount(0)

        await dialog(page)
            .getByRole('button', { name: / on forge-01$/ })
            .click()

        const apps = dialog(page).getByRole('group', { name: 'Apps and databases on forge-01' }).getByRole('checkbox')

        await expect(apps.first()).toBeChecked()
        await expect(apps.first()).toBeDisabled()
        await expect(dialog(page).getByTestId('share-signs-in')).toHaveText(
            'Noor Haddad signs in to forge-01 over SSH, which reaches everything on it, so it all comes with the server. To share only some of its apps, untick forge-01 and tick them.'
        )
    })

    test('a developer is never shown everything, and each app on a shared server is a tick of its own', async ({
        page
    }) => {
        await gotoSettings(page, 'Team')
        await member(page, 'developer@coresmith.test').getByRole('button', { name: 'Access for Lena Brandt' }).click()

        await expect(dialog(page)).toContainText(
            'Lena Brandt sees what they add and what you tick. A project brings every environment in it. A server lets Lena Brandt add apps and databases to it and shows only the apps you tick under it.'
        )
        await expect(dialog(page).getByRole('switch', { name: /^Everything in / })).toHaveCount(0)
        await expect(dialog(page).getByRole('checkbox', { name: 'forge-02', exact: true })).toBeChecked()

        await dialog(page)
            .getByRole('button', { name: / on forge-02$/ })
            .click()

        const staging = dialog(page).getByRole('checkbox', { name: 'atlas-staging', exact: true })

        await expect(staging).not.toBeChecked()
        await expect(staging).toBeEnabled()
        await expect(dialog(page).getByTestId('share-database')).toHaveCount(0)
    })

    for (const theme of ['light', 'dark'] as const) {
        test(`the access dialog with a server's apps held back or locked is accessible in ${theme}`, async ({
            page
        }) => {
            await useTheme(page, theme)
            await gotoSettings(page, 'Team')
            await member(page, 'narrowed@coresmith.test')
                .getByRole('button', { name: 'Access for Noor Haddad' })
                .click()
            await dialog(page)
                .getByRole('button', { name: / on forge-01$/ })
                .click()
            await expect(dialog(page).getByTestId('share-signs-in')).toBeVisible()

            expect(await blockingViolations(page)).toEqual([])
        })
    }

    test('an owner invites someone to only what they share', async ({ page }, testInfo) => {
        const email = `${own(testInfo, 'shared-invite')}@coresmith.test`

        await gotoSettings(page, 'Team')
        await page.getByRole('button', { name: 'Invite someone' }).click()
        await expect(dialog(page)).toContainText('You can change this any time from their row.')

        await dialog(page).getByLabel('Email address').fill(email)
        await dialog(page).getByLabel('Access').selectOption('shared')
        await dialog(page).getByRole('button', { name: 'Add to the team' }).click()

        await expect(toast(page)).toContainText(`Invitation created.`)
        await expect(dialog(page)).toHaveCount(0)
    })
})

asNarrowed.describe('an admin', () => {
    asNarrowed("sees nobody's access and offers no access choice when inviting", async ({ page }) => {
        await gotoSettings(page, 'Team')

        await expect(member(page, 'member@coresmith.test')).toBeVisible()
        await expect(page.getByRole('button', { name: /^Access for / })).toHaveCount(0)
        await expect(page.getByTestId('member-access')).toHaveCount(0)

        await page.getByRole('button', { name: 'Invite someone' }).click()
        await expect(dialog(page).getByLabel('Role')).toBeVisible()
        await expect(dialog(page).getByLabel('Access')).toHaveCount(0)
    })
})
