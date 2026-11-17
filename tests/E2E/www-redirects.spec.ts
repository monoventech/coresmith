// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

import { expect, gotoApplication, isMobile, test } from './support/fixtures'

test.describe('www redirects', () => {
    test("an apex domain's www name shows as a redirect, and stays quiet until it points here", async ({
        page
    }, testInfo) => {
        test.skip(isMobile(testInfo), "The Domains card is not drawn below 640px, as the mobile prototype's app page")

        await gotoApplication(page, 'bench-01', 'apex-shop')

        const apex = page.getByTestId('domain-row').filter({ hasText: 'apex-shop.invalid' })
        const redirect = page.getByTestId('domain-redirect-row')

        await expect(apex.locator('[data-slot="badge"]')).toHaveText('TLS')
        await expect(redirect).toHaveCount(1)
        await expect(redirect).toContainText('www.apex-shop.invalid')
        await expect(redirect.getByText('redirect', { exact: false })).toHaveAttribute(
            'title',
            'Sends visitors to apex-shop.invalid'
        )
        await expect(redirect.getByText('to apex-shop.invalid')).toBeAttached()
        await expect(redirect.locator('[data-slot="badge"]')).toHaveText('No certificate')
        await expect(redirect.locator('[data-slot="badge"]')).not.toHaveClass(/badge-warning/)
        await expect(
            page
                .getByTestId('domain-advice')
                .getByText('www.apex-shop.invalid sends visitors to apex-shop.invalid once it points at this server.')
        ).toBeVisible()
    })
})
