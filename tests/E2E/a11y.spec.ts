import AxeBuilder from '@axe-core/playwright'
import { expect, test } from './support/fixtures'

const PAGES = [
    ['/dashboard', 'Dashboard'],
    ['/servers', 'Servers list'],
    ['/servers/create', 'Add a server'],
    ['/ssh-keys', 'SSH keys'],
    ['/settings/profile', 'Profile settings'],
    ['/teams', 'Team management'],
]

test.describe('Accessibility', () => {
    for (const [path, label] of PAGES) {
        test(`${label} has no serious or critical accessibility violations`, async ({ page }) => {
            await page.goto(path)
            await page.waitForLoadState('networkidle')

            const results = await new AxeBuilder({ page })
                .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
                .analyze()

            const blocking = results.violations.filter((violation) =>
                ['serious', 'critical'].includes(violation.impact ?? ''),
            )

            expect(
                blocking.map((violation) => `${violation.id}: ${violation.help}`),
                `${label} accessibility violations`,
            ).toEqual([])
        })

        test(`${label} passes contrast in dark mode`, async ({ page }) => {
            await page.emulateMedia({ colorScheme: 'dark' })
            await page.goto(path)
            await page.waitForLoadState('networkidle')

            const results = await new AxeBuilder({ page }).withRules(['color-contrast']).analyze()

            const blocking = results.violations.filter((violation) =>
                ['serious', 'critical'].includes(violation.impact ?? ''),
            )

            expect(
                blocking.flatMap((violation) => violation.nodes.map((node) => node.failureSummary ?? violation.id)),
                `${label} dark mode contrast`,
            ).toEqual([])
        })
    }
})
