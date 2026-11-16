import { asGuest, main, opensMonovenInNewTab, test } from '../support/fixtures'

test('coresmith cloud ends every page with Made by Monoven as text, which opens monoven.com in a new tab', async ({
    page
}) => {
    await page.goto('/monoven/dashboard')

    await opensMonovenInNewTab(page, main(page).locator('footer'))
})

asGuest('the coresmith cloud sign in page ends with it too', async ({ page }) => {
    await page.goto('/login')

    await opensMonovenInNewTab(page, page.getByRole('contentinfo'))
})
