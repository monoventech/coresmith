// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

import type { Page } from '@playwright/test'
import { chromium } from '@playwright/test'
import { execFileSync } from 'node:child_process'
import { parseArgs } from 'node:util'

const { values } = parseArgs({
    options: {
        panel: { type: 'string' },
        install: { type: 'string' },
        proxy: { type: 'string' },
        node: { type: 'string', default: 'cs-node1' },
        nodeIp: { type: 'string' },
        server: { type: 'string', default: 'corpus-node' }
    }
})

const panel = (values.panel ?? '').replace(/\/$/, '')
const email = process.env.CORPUS_EMAIL ?? ''
const password = process.env.CORPUS_PASSWORD ?? ''

if (panel === '' || values.nodeIp === undefined || email === '' || password === '') {
    console.error(
        'Usage: CORPUS_EMAIL=… CORPUS_PASSWORD=… bun tests/Corpus/setup.ts --panel https://… --nodeIp 192.168.104.3 [--install <setup link>] [--proxy socks5://…]'
    )
    process.exit(2)
}

async function until(read: () => Promise<boolean>, seconds: number, every = 5): Promise<boolean> {
    const end = Date.now() + seconds * 1000

    while (Date.now() < end) {
        if (await read()) {
            return true
        }

        await new Promise((resolve) => setTimeout(resolve, every * 1000))
    }

    return false
}

async function signIn(page: Page) {
    if (values.install !== undefined) {
        await page.goto(values.install)
        await page.getByLabel('Your name').fill('Corpus Owner')
        await page.getByLabel('Email', { exact: true }).fill(email)
        await page.getByLabel('Password', { exact: true }).fill(password)
        await page.getByLabel('Confirm password').fill(password)
        await page.getByLabel('Instance name').fill('Corpus')
        await page.getByRole('button', { name: 'Create account and finish setup' }).click()
        await page.waitForURL((url) => !url.pathname.startsWith('/install'), { timeout: 120_000 })

        return
    }

    await page.goto(`${panel}/login`)
    await page.getByLabel('Email address').fill(email)
    await page.getByLabel('Password', { exact: true }).fill(password)
    await page.getByRole('button', { name: 'Sign in' }).click()
    await page.waitForURL((url) => !url.pathname.endsWith('/login'), { timeout: 60_000 })
}

const browser = await chromium.launch({ proxy: values.proxy === undefined ? undefined : { server: values.proxy } })
const page = await (
    await browser.newContext({ ignoreHTTPSErrors: true, viewport: { width: 1440, height: 1600 } })
).newPage()

await signIn(page)
await page.goto(`${panel}/servers`)

const existing = page.getByRole('link', { name: values.server ?? 'corpus-node', exact: true }).first()

if ((await existing.count()) > 0) {
    await existing.click()
} else {
    await page.goto(`${panel}/servers/create`)
    await page.getByLabel('Provider').selectOption({ label: 'Bring your own VM' })
    await page.getByLabel('Server name').fill(values.server ?? 'corpus-node')
    await page.getByLabel('IP address').fill(values.nodeIp)
    await page.getByLabel('SSH user').fill('root')
    await page.getByRole('button', { name: 'Add server' }).click()
}

const command = page.getByTestId('connect-command')

if (
    await command.waitFor({ timeout: 30_000 }).then(
        () => true,
        () => false
    )
) {
    execFileSync(
        process.env.LIMACTL ?? 'limactl',
        ['shell', values.node ?? 'cs-node1', 'sudo', 'bash', '-c', (await command.innerText()).trim()],
        { stdio: 'inherit' }
    )
    console.log("Added Coresmith's key to the node.")
}

const provisioned = await until(
    async () => {
        await page.reload()

        for (const name of ['Proceed with provisioning', 'Check the server', 'Test connection']) {
            const button = page.getByRole('button', { name, exact: true })

            if ((await button.count()) > 0 && (await button.first().isEnabled())) {
                console.log(`Pressing ${name}.`)
                await button.first().click()
                await page.waitForTimeout(3_000)

                break
            }
        }

        return (await page.getByRole('link', { name: 'Add an app' }).count()) > 0
    },
    30 * 60,
    20
)

console.log(provisioned ? 'The node is provisioned.' : 'The node did not finish provisioning in 30 minutes.')
await browser.close()
process.exit(provisioned ? 0 : 1)
