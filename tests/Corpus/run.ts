// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

import type { Page } from '@playwright/test'
import { chromium } from '@playwright/test'
import { execFileSync } from 'node:child_process'
import { appendFileSync, existsSync, mkdirSync, readFileSync } from 'node:fs'
import { parseArgs } from 'node:util'

export type Expectation =
    | { verdict: 'ready'; type: string; framework: string; js_runtime?: string | null; web_directory?: string | null }
    | { verdict: 'blocked' | 'unsure'; reason: string }

export type Entry = { name: string; url: string; branch: string; expect?: Expectation; why?: string }

export type Deploy = { status: string; reason: string | null; live: string | null; seconds: number; log: string }

export type Result = {
    name: string
    url: string
    branch: string
    verdict: string
    detail: string
    fields: Record<string, string | null>
    version: string | null
    deploy: Deploy | null
    matched: boolean | null
    at: string
}

const FINAL = ['Deployed', 'Failed', 'Cancelled']

const { values } = parseArgs({
    options: {
        set: { type: 'string', default: 'tests/Corpus/fixed.json' },
        panel: { type: 'string' },
        server: { type: 'string' },
        proxy: { type: 'string' },
        node: { type: 'string', default: 'cs-node1' },
        out: { type: 'string' },
        only: { type: 'string' },
        part: { type: 'string', default: '1/1' },
        detectOnly: { type: 'boolean', default: false },
        keep: { type: 'boolean', default: false },
        deployMinutes: { type: 'string', default: '25' }
    }
})

const panel = (values.panel ?? '').replace(/\/$/, '')
const server = values.server ?? ''
const email = process.env.CORPUS_EMAIL ?? ''
const password = process.env.CORPUS_PASSWORD ?? ''

if (panel === '' || server === '' || email === '' || password === '') {
    console.error(
        'Usage: CORPUS_EMAIL=… CORPUS_PASSWORD=… bun tests/Corpus/run.ts --panel https://… --server name [--set file] [--proxy socks5://…] [--out file] [--only name] [--detectOnly]'
    )
    process.exit(2)
}

const entries = (JSON.parse(readFileSync(values.set ?? '', 'utf8')) as Entry[]).filter(
    (entry) => values.only === undefined || entry.name === values.only
)
const out =
    values.out ??
    `storage/corpus/${(values.set ?? 'set').replace(/^.*\//, '').replace(/\.json$/, '')}-${Date.now()}.jsonl`
const done = new Set(
    existsSync(out)
        ? readFileSync(out, 'utf8')
              .split('\n')
              .filter((line) => line.trim() !== '')
              .map((line) => (JSON.parse(line) as Result).name)
        : []
)

mkdirSync(out.replace(/\/[^/]*$/, ''), { recursive: true })

function slug(text: string): string {
    return text
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-|-$/g, '')
        .slice(0, 40)
}

async function until<T>(read: () => Promise<T | null>, seconds: number, every = 2): Promise<T | null> {
    const end = Date.now() + seconds * 1000

    while (Date.now() < end) {
        const value = await read()

        if (value !== null) {
            return value
        }

        await new Promise((resolve) => setTimeout(resolve, every * 1000))
    }

    return null
}

function verdictOf(text: string): string {
    if (text.includes("can't deploy as is")) {
        return 'blocked'
    }

    if (text.includes("Coresmith couldn't read this repository")) {
        return 'unreadable'
    }

    if (text.includes('Filled in from the repository')) {
        return 'ready'
    }

    if (text.includes('Filled in what the repository settles')) {
        return 'unsure'
    }

    if (text.includes('git@ repositories')) {
        return 'needs_key'
    }

    return 'failed'
}

async function fieldsOf(page: Page): Promise<Record<string, string | null>> {
    const runtime = page.getByLabel('Runtime', { exact: true })

    return {
        type: await page.getByLabel('Language', { exact: true }).inputValue(),
        framework: await page.getByLabel('Framework', { exact: true }).inputValue(),
        js_runtime: (await runtime.count()) > 0 ? await runtime.inputValue() : null,
        web_directory: await page.getByLabel('Web root', { exact: true }).inputValue()
    }
}

function matches(entry: Entry, result: Result): boolean | null {
    const expected = entry.expect

    if (expected === undefined) {
        return null
    }

    if (expected.verdict !== 'ready') {
        return result.verdict === expected.verdict && result.detail.includes(expected.reason)
    }

    const fields =
        result.fields.type === expected.type &&
        result.fields.framework === expected.framework &&
        (expected.js_runtime === undefined || result.fields.js_runtime === expected.js_runtime) &&
        (expected.web_directory === undefined ||
            expected.web_directory === null ||
            result.fields.web_directory === expected.web_directory)
    const live = result.deploy?.live ?? null
    const answered = live === null || !/\b5\d\d\b/.test(live)

    return result.verdict === 'ready' && fields && result.deploy?.status === 'Deployed' && answered
}

async function deploy(page: Page, name: string): Promise<Deploy> {
    const started = Date.now()

    await page.getByRole('button', { name: 'Deploy', exact: true }).click()
    await page.waitForURL(/\/deployments\//, { timeout: 60_000 })

    const status = await until(
        async () => {
            const text = ((await page.getByTestId('deployment-status').textContent()) ?? '').trim()

            if (FINAL.includes(text)) {
                return text
            }

            if ((Date.now() - started) % 60_000 < 10_000) {
                await page.reload()
            }

            return null
        },
        Number(values.deployMinutes) * 60,
        10
    )

    const reason =
        (await page.getByTestId('failure-reason').count()) > 0
            ? await page.getByTestId('failure-reason').innerText()
            : null
    const live =
        (await page.getByTestId('deploy-live-answer').count()) > 0
            ? await page.getByTestId('deploy-live-answer').innerText()
            : null
    const log =
        (await page.getByTestId('deployment-log').count()) > 0
            ? await page.getByTestId('deployment-log').innerText()
            : ''

    console.log(`  ${name}: ${status ?? 'timed out'}${reason ? ` (${reason.slice(0, 120)})` : ''}`)

    return {
        status: status ?? 'Timed out',
        reason,
        live,
        seconds: Math.round((Date.now() - started) / 1000),
        log: log.slice(-8000)
    }
}

async function remove(page: Page, name: string): Promise<void> {
    await page.goBack()
    await page.getByRole('heading', { level: 1, name, exact: true }).waitFor({ timeout: 30_000 })
    await page.getByRole('button', { name: 'Remove this app' }).click()
    await page.getByRole('button', { name: 'Remove the app' }).click()
    await page.waitForURL((url) => !url.pathname.includes('/applications/'), { timeout: 60_000 })

    const prefix = name.slice(0, 18).replace(/-+$/, '')

    execFileSync(process.env.LIMACTL ?? 'limactl', [
        'shell',
        values.node ?? 'cs-node1',
        'sudo',
        'bash',
        '-c',
        `rm -rf /var/coresmith/apps/${prefix}* /etc/coresmith/env/${prefix}* /home/coresmith-app-${prefix}*`
    ])
}

const browser = await chromium.launch({ proxy: values.proxy === undefined ? undefined : { server: values.proxy } })
const context = await browser.newContext({ ignoreHTTPSErrors: true, viewport: { width: 1440, height: 1600 } })
const page = await context.newPage()

await page.goto(`${panel}/login`)
await page.getByLabel('Email address').fill(email)
await page.getByLabel('Password', { exact: true }).fill(password)
await page.getByRole('button', { name: 'Sign in' }).click()
await page.waitForURL((url) => !url.pathname.endsWith('/login'), { timeout: 60_000 })
await page.goto(`${panel}/servers`)
await page.getByRole('link', { name: server, exact: true }).first().click()
await page.waitForURL(/\/servers\/[^/?#]+(?:[?#].*)?$/, { timeout: 30_000 })
await page.getByRole('link', { name: 'Add an app' }).first().waitFor({ timeout: 30_000 })

const serverPage = page.url()

const [part, parts] = (values.part ?? '1/1').split('/').map(Number)

for (const [index, entry] of entries.entries()) {
    if (done.has(entry.name) || index % parts !== part - 1) {
        continue
    }

    let result: Result

    try {
        const name = `c${String(index + 1).padStart(3, '0')}-${slug(entry.name)}`.slice(0, 48)

        console.log(`${index + 1}/${entries.length} ${entry.name}`)

        await page.goto(serverPage)
        await page.getByRole('link', { name: 'Add an app' }).first().click({ timeout: 60_000 })
        await page.getByLabel('Name', { exact: true }).fill(name)

        if ((await page.getByLabel('Source', { exact: true }).count()) > 0) {
            await page.getByLabel('Source', { exact: true }).selectOption({ label: 'A Git URL' })
        }

        await page.getByLabel('Repository', { exact: true }).fill(entry.url)
        await page.getByLabel('Branch', { exact: true }).fill(entry.branch)

        const detail =
            (await until(async () => {
                const text = ((await page.getByTestId('stack-detection').innerText()) ?? '').trim()

                return text === '' || text.includes('Reading the repository') ? null : text
            }, 240)) ?? 'Detection timed out'

        const notes = await page
            .locator('form .text-warning-strong')
            .allInnerTexts()
            .catch(() => [])
        const verdict = verdictOf(detail)
        const fields = await fieldsOf(page)
        const version = await page
            .locator('#runtime-version-repository')
            .innerText({ timeout: 2_000 })
            .catch(() => null)
        let deployed: Deploy | null = null

        if (verdict === 'ready' && !values.detectOnly) {
            await page.getByLabel('Domain', { exact: true }).fill(`${name}.internal`)
            await page.getByRole('button', { name: 'Add the app' }).click()
            await page.getByRole('heading', { level: 1, name, exact: true }).waitFor({ timeout: 60_000 })
            deployed = await deploy(page, name)

            if (!values.keep) {
                await remove(page, name).catch((error: unknown) =>
                    console.log(`  could not remove ${name}: ${String(error)}`)
                )
            }
        }

        result = {
            name: entry.name,
            url: entry.url,
            branch: entry.branch,
            verdict,
            detail: [detail, ...notes].join('\n'),
            fields,
            version,
            deploy: deployed,
            matched: null,
            at: new Date().toISOString()
        }
    } catch (error) {
        if (String(error).includes('has been closed')) {
            throw error
        }

        console.log(`  harness error: ${String(error).split('\n')[0]}`)
        result = {
            name: entry.name,
            url: entry.url,
            branch: entry.branch,
            verdict: 'harness',
            detail: String(error).split('\n')[0],
            fields: {},
            version: null,
            deploy: null,
            matched: null,
            at: new Date().toISOString()
        }
        await page
            .screenshot({ path: out.replace(/\.jsonl$/, `-${index + 1}.png`), fullPage: true })
            .catch(() => undefined)
        await page.goto(serverPage).catch(() => undefined)
    }

    result.matched = matches(entry, result)
    appendFileSync(out, JSON.stringify(result) + '\n')
    console.log(
        `  ${result.verdict}${result.matched === null ? '' : result.matched ? ', as expected' : ', NOT as expected'}`
    )
}

await browser.close()
console.log(`Results: ${out}`)
