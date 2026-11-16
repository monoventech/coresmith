import { asGuest, main, opensMonovenInNewTab, test } from './support/fixtures'

test('every page ends with Made by Monoven as text, which opens monoven.com in a new tab', async ({ page }) => {
    await page.goto('/dashboard')

    await opensMonovenInNewTab(page, main(page).locator('footer'))
})

asGuest('the sign in page ends with it too', async ({ page }) => {
    await page.goto('/login')

    await opensMonovenInNewTab(page, page.getByRole('contentinfo'))
})
