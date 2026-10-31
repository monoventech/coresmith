// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

import type { Page } from '@playwright/test'
import {
    filterText,
    filterUrl,
    scrubBreadcrumb,
    scrubEvent,
    scrubSpan,
    sentrySettings,
    startSentry
} from '../../resources/js/lib/sentry'
import { asGuest, expect, test } from './support/fixtures'

const PANEL = 'https://panel.example.com'
const TOKEN = '9f8e7d6c5b4a39281706f5e4d3c2b1a0'
const HASH = '1f2e3d4c5b6a79881706f5e4d3c2b1a0aabbccdd'
const MASKED = '%5BFiltered%5D'

const ADDRESSES: [string, string, string][] = [
    ['a setup link', `${PANEL}/install?token=s3cr3t-setup&step=2`, `${PANEL}/install?token=${MASKED}&step=2`],
    ['an oauth callback query', 'code=abc123&state=xyz789', `code=${MASKED}&state=${MASKED}`],
    [
        'a signed link',
        `${PANEL}/verify?id=7&signature=9f8e7d6c&expires=17`,
        `${PANEL}/verify?id=7&signature=${MASKED}&expires=17`
    ],
    ['any letter case', 'CODE=abc123&State=xyz789', `CODE=${MASKED}&State=${MASKED}`],
    ['a fragment is left alone', `${PANEL}/callback?code=abc123#top`, `${PANEL}/callback?code=${MASKED}#top`],
    ['every other pair byte for byte', 'a=1&b=%20x&c[]=2&token=t&d=a+b', `a=1&b=%20x&c[]=2&token=${MASKED}&d=a+b`],
    ['names that only end like a filtered one', 'barcode=abc123&postcode=n1', 'barcode=abc123&postcode=n1'],
    ['an address with nothing to filter', `${PANEL}/servers?page=2&sort=name`, `${PANEL}/servers?page=2&sort=name`],
    [
        'a password reset link',
        `${PANEL}/reset-password/${TOKEN}?email=maya%40monoven.io`,
        `${PANEL}/reset-password/${MASKED}?email=${MASKED}`
    ],
    [
        'a password reset link under a team prefix',
        `${PANEL}/acme/reset-password/${TOKEN}?email=maya%40monoven.io`,
        `${PANEL}/acme/reset-password/${MASKED}?email=${MASKED}`
    ],
    [
        'a password reset link on a path without a host',
        `/reset-password/${TOKEN}?email=maya%40monoven.io`,
        `/reset-password/${MASKED}?email=${MASKED}`
    ],
    ['a password reset query', 'email=maya%40monoven.io&lang=en', `email=${MASKED}&lang=en`],
    [
        'a verification link',
        `${PANEL}/email/verify/7/${HASH}?expires=1760000000&signature=ab12cd34`,
        `${PANEL}/email/verify/7/${MASKED}?expires=1760000000&signature=${MASKED}`
    ],
    [
        'a verification link under a team prefix',
        `${PANEL}/acme/verify-email/7/${HASH}?expires=1760000000&signature=ab12cd34`,
        `${PANEL}/acme/verify-email/7/${MASKED}?expires=1760000000&signature=${MASKED}`
    ],
    ['a verification query', 'expires=1760000000&signature=ab12cd34', `expires=1760000000&signature=${MASKED}`],
    ['a reset page with no token yet', `${PANEL}/reset-password`, `${PANEL}/reset-password`],
    [
        'a path that only starts like the reset page',
        `${PANEL}/reset-password-help/abc`,
        `${PANEL}/reset-password-help/abc`
    ],
    ['the verification notice', `${PANEL}/email/verify`, `${PANEL}/email/verify`],
    [
        'names with an array suffix',
        'code[]=abc&token[0]=def&state%5B%5D=ghi&Email[x]=jkl&step=2',
        `code[]=${MASKED}&token[0]=${MASKED}&state%5B%5D=${MASKED}&Email[x]=${MASKED}&step=2`
    ],
    [
        'names that end in signature',
        'X-Amz-Signature=abc123&X-Amz-Date=20261030&oauth_signature=def456',
        `X-Amz-Signature=${MASKED}&X-Amz-Date=20261030&oauth_signature=${MASKED}`
    ],
    [
        'html-escaped separators',
        'code=abc123&amp;state=xyz789&amp;page=2',
        `code=${MASKED}&amp;state=${MASKED}&amp;page=2`
    ],
    [
        'an email address under any query name',
        'user=maya%40monoven.io&q=maya@monoven.io&page=2',
        `user=${MASKED}&q=${MASKED}&page=2`
    ],
    [
        'an email address in a path',
        `${PANEL}/invitations/maya@monoven.io/accept`,
        `${PANEL}/invitations/${MASKED}/accept`
    ],
    [
        'a query inside a hash route',
        `${PANEL}/#/callback?code=abc123&page=2`,
        `${PANEL}/#/callback?code=${MASKED}&page=2`
    ]
]

const TEXTS: [string, string, string][] = [
    [
        'a url in a sentence',
        'GET https://git.example.com/login/callback?code=abc123&state=xyz789&x=1 returned 502',
        `GET https://git.example.com/login/callback?code=${MASKED}&state=${MASKED}&x=1 returned 502`
    ],
    [
        'a url in json',
        '{"url":"https://git.example.com/cb?signature=abc123","status":502}',
        `{"url":"https://git.example.com/cb?signature=${MASKED}","status":502}`
    ],
    [
        'a url in brackets',
        '(see https://git.example.com/cb?code=abc123)',
        `(see https://git.example.com/cb?code=${MASKED})`
    ],
    [
        'a reset link',
        `Could not open ${PANEL}/acme/reset-password/${TOKEN}?email=maya%40monoven.io again`,
        `Could not open ${PANEL}/acme/reset-password/${MASKED}?email=${MASKED} again`
    ],
    [
        'a verification link',
        `Rejected ${PANEL}/email/verify/7/${HASH}?expires=1760000000&signature=ab12cd34 now`,
        `Rejected ${PANEL}/email/verify/7/${MASKED}?expires=1760000000&signature=${MASKED} now`
    ],
    [
        'array style and signature names',
        'Fetch https://s3.example.com/a?code[]=abc&X-Amz-Signature=def456 failed',
        `Fetch https://s3.example.com/a?code[]=${MASKED}&X-Amz-Signature=${MASKED} failed`
    ],
    [
        'plain words with the same names',
        'The state=running and code=200 and email=none lines stay',
        'The state=running and code=200 and email=none lines stay'
    ],
    [
        'a url with nothing to filter',
        'GET https://git.example.com/repos?page=2&per_page=50 returned 200',
        'GET https://git.example.com/repos?page=2&per_page=50 returned 200'
    ],
    [
        'html-escaped separators',
        'GET /callback?code=abc123&amp;state=xyz789&amp;page=2 failed',
        `GET /callback?code=${MASKED}&amp;state=${MASKED}&amp;page=2 failed`
    ],
    ['an email address', 'sent the invitation to maya@monoven.io now', 'sent the invitation to [Filtered] now'],
    ['an email address with a tag', 'maya+billing@monoven.io bounced', '[Filtered] bounced'],
    [
        'a mail transport error',
        'Expected response code "250" but got code "550", with message "550 5.1.1 <maya@monoven.io>: Recipient address rejected"',
        'Expected response code "250" but got code "550", with message "550 5.1.1 <[Filtered]>: Recipient address rejected"'
    ],
    [
        'a percent-encoded email address',
        'GET /callback?user=maya%40monoven.io failed',
        'GET /callback?user=[Filtered] failed'
    ],
    [
        'a git remote address',
        'Permission denied (publickey) for git@github.com:acme/app.git',
        'Permission denied (publickey) for git@github.com:acme/app.git'
    ],
    [
        'a git host next to an email address',
        'git@gitlab.example.com refused maya@monoven.io',
        'git@gitlab.example.com refused [Filtered]'
    ],
    [
        'an ssh login to an ip address',
        'deploy@203.0.113.9: connection refused',
        'deploy@203.0.113.9: connection refused'
    ],
    [
        'a package version and a scope',
        'installed node@24.21.0 and @types/node',
        'installed node@24.21.0 and @types/node'
    ],
    ['an email address at an accented domain', 'maya@münchen.de bounced', '[Filtered] bounced'],
    ['an email address in another script', 'maya@пример.рф bounced', '[Filtered] bounced'],
    ['an accented mailbox', 'josé@example.com bounced', '[Filtered] bounced'],
    ['a mailbox with an accent inside', 'müller@example.com bounced', '[Filtered] bounced'],
    ['a mailbox with an apostrophe', "o'brien@company.ie bounced", '[Filtered] bounced'],
    [
        'a quoted email address keeps its quotes',
        "Duplicate entry 'maya@monoven.io' for key 'users.users_email_unique'",
        "Duplicate entry '[Filtered]' for key 'users.users_email_unique'"
    ],
    [
        'a git host next to an accented email address',
        "'git@github.com:acme/app.git' refused josé@example.com",
        "'git@github.com:acme/app.git' refused [Filtered]"
    ]
]

const STALLS: [string, string][] = [
    ['apostrophes between letters', "a'".repeat(270_000) + '@monoven.io'],
    ['accented letters', 'ü'.repeat(270_000) + '@monoven.io'],
    ['accented addresses', 'ü@ü.'.repeat(135_000)],
    ['query separators', '?a&'.repeat(200_000) + 'token=abc'],
    ['signature lookalikes', ('&' + 'x'.repeat(63)).repeat(10_000)]
]

async function withMeta(content: string | null, run: () => void | Promise<void>): Promise<void> {
    const original = Object.getOwnPropertyDescriptor(globalThis, 'document')

    Object.defineProperty(globalThis, 'document', {
        configurable: true,
        value: { querySelector: () => (content === null ? null : { getAttribute: () => content }) }
    })

    try {
        await run()
    } finally {
        if (original) {
            Object.defineProperty(globalThis, 'document', original)
        } else {
            delete (globalThis as { document?: unknown }).document
        }
    }
}

test.describe('addresses are masked before they leave the browser', () => {
    for (const [name, input, expected] of ADDRESSES) {
        test(`filterUrl masks ${name}`, () => {
            expect(filterUrl(input)).toBe(expected)
            expect(filterUrl(filterUrl(input))).toBe(expected)
        })
    }

    for (const [name, input, expected] of TEXTS) {
        test(`filterText masks ${name}`, () => {
            expect(filterText(input)).toBe(expected)
            expect(filterText(filterText(input))).toBe(expected)
        })
    }

    for (const [name, text] of STALLS) {
        test(`a long run of ${name} is masked without stalling`, () => {
            const started = performance.now()

            filterText(text)
            filterUrl(text)

            expect(text.length).toBeGreaterThan(250_000)
            expect(performance.now() - started).toBeLessThan(2_000)
        })
    }
})

test.describe('events, breadcrumbs and spans are scrubbed', () => {
    test('an event keeps no part of a reset link', () => {
        const event = scrubEvent({
            event_id: 'a1b2',
            level: 'error',
            release: 'coresmith@1.0.0',
            transaction: `/acme/reset-password/${TOKEN}`,
            message: `Opened ${PANEL}/reset-password/${TOKEN}?email=maya%40monoven.io again`,
            request: {
                url: `${PANEL}/reset-password/${TOKEN}?email=maya%40monoven.io`,
                query_string: 'email=maya%40monoven.io&lang=en',
                headers: { Referer: `${PANEL}/reset-password/${TOKEN}`, 'User-Agent': 'Mozilla/5.0' }
            },
            contexts: { culture: { locale: 'en-US' }, trace: { trace_id: 't1' } },
            extra: { arguments: [`${PANEL}/reset-password/${TOKEN}`], attempts: 2 },
            tags: { page: `${PANEL}/servers?code=abc123`, auth_token: 'abc' },
            exception: {
                values: [
                    {
                        type: 'Error',
                        value: `Failed ${PANEL}/callback?token=abc for maya@monoven.io`,
                        stacktrace: {
                            frames: [
                                {
                                    filename: `${PANEL}/reset-password/${TOKEN}`,
                                    abs_path: `${PANEL}/reset-password/${TOKEN}?email=maya%40monoven.io`,
                                    function: 'onload',
                                    lineno: 3,
                                    in_app: true
                                }
                            ]
                        }
                    }
                ]
            }
        } as never)

        expect(event).toEqual({
            event_id: 'a1b2',
            level: 'error',
            release: 'coresmith@1.0.0',
            transaction: `/acme/reset-password/${MASKED}`,
            message: `Opened ${PANEL}/reset-password/${MASKED}?email=${MASKED} again`,
            request: {
                url: `${PANEL}/reset-password/${MASKED}?email=${MASKED}`,
                query_string: `email=${MASKED}&lang=en`,
                headers: { Referer: `${PANEL}/reset-password/${MASKED}`, 'User-Agent': 'Mozilla/5.0' }
            },
            contexts: { culture: { locale: 'en-US' }, trace: { trace_id: 't1' } },
            extra: { arguments: [`${PANEL}/reset-password/${MASKED}`], attempts: 2 },
            tags: { page: `${PANEL}/servers?code=${MASKED}`, auth_token: '[Filtered]' },
            exception: {
                values: [
                    {
                        type: 'Error',
                        value: `Failed ${PANEL}/callback?token=${MASKED} for [Filtered]`,
                        stacktrace: {
                            frames: [
                                {
                                    filename: `${PANEL}/reset-password/${MASKED}`,
                                    abs_path: `${PANEL}/reset-password/${MASKED}?email=${MASKED}`,
                                    function: 'onload',
                                    lineno: 3,
                                    in_app: true
                                }
                            ]
                        }
                    }
                ]
            }
        })
    })

    test('an event that carries only what it needs comes back as it was', () => {
        const bare = { event_id: 'a1b2', level: 'error', request: { url: `${PANEL}/servers?page=2` } }

        expect(scrubEvent(bare as never)).toEqual(bare)
        expect(scrubEvent({ event_id: 'a1b2' } as never)).toEqual({ event_id: 'a1b2' })
    })

    test('every kind of breadcrumb has its addresses masked', () => {
        const navigation = scrubBreadcrumb({
            category: 'navigation',
            data: {
                from: `/reset-password/${TOKEN}?email=maya%40monoven.io`,
                to: `/acme/email/verify/7/${HASH}?signature=ab12cd34`
            }
        })
        const fetch = scrubBreadcrumb({
            category: 'fetch',
            type: 'http',
            level: 'warning',
            timestamp: 1760000000.5,
            data: { method: 'GET', url: `${PANEL}/x?code=aaa111&page=2`, status_code: 200 }
        })
        const xhr = scrubBreadcrumb({
            category: 'xhr',
            type: 'http',
            data: { method: 'POST', url: `${PANEL}/x?state=bbb222`, status_code: 500 }
        })

        expect(navigation).toEqual({
            category: 'navigation',
            data: {
                from: `/reset-password/${MASKED}?email=${MASKED}`,
                to: `/acme/email/verify/7/${MASKED}?signature=${MASKED}`
            }
        })
        expect(fetch).toEqual({
            category: 'fetch',
            type: 'http',
            level: 'warning',
            timestamp: 1760000000.5,
            data: { method: 'GET', url: `${PANEL}/x?code=${MASKED}&page=2`, status_code: 200 }
        })
        expect(xhr.data).toEqual({ method: 'POST', url: `${PANEL}/x?state=${MASKED}`, status_code: 500 })
    })

    test('a breadcrumb message is masked, secret keys are filtered and what is not plain data is dropped', () => {
        const failure = new Error(`Failed ${PANEL}/reset-password/${TOKEN}`)
        const logged = scrubBreadcrumb({
            category: 'console',
            level: 'error',
            message: `Failed to load ${PANEL}/reset-password/${TOKEN}?email=maya%40monoven.io`,
            data: { logger: 'console', arguments: ['Failed to load', failure, { token: 'abc' }, 7, null] }
        })

        expect(logged).toEqual({
            category: 'console',
            level: 'error',
            message: `Failed to load ${PANEL}/reset-password/${MASKED}?email=${MASKED}`,
            data: { logger: 'console', arguments: ['Failed to load', '[Filtered]', { token: '[Filtered]' }, 7, null] }
        })
        expect(JSON.stringify(logged)).not.toContain(TOKEN)
        expect(scrubBreadcrumb({ category: 'ui.click', message: 'body > button.deploy' })).toEqual({
            category: 'ui.click',
            message: 'body > button.deploy'
        })
    })

    test('a span has its name and its address attributes masked', () => {
        const span = scrubSpan({
            trace_id: 't1',
            span_id: 's1',
            name: `GET /reset-password/${TOKEN}`,
            status: 'ok',
            is_segment: true,
            start_timestamp: 1760000000,
            attributes: {
                'sentry.op': 'http.client',
                'url.full': `${PANEL}/reset-password/${TOKEN}?email=maya%40monoven.io`,
                'http.query': '?code=abc123&page=2',
                'http.request.header.referer': [`${PANEL}/servers?token=abc`],
                'server.port': 443,
                'sentry.sample_rate': { value: 0.5 },
                wrapped: { value: `${PANEL}/x?state=zz`, unit: 'none' }
            }
        })

        expect(span).toEqual({
            trace_id: 't1',
            span_id: 's1',
            name: `GET /reset-password/${MASKED}`,
            status: 'ok',
            is_segment: true,
            start_timestamp: 1760000000,
            attributes: {
                'sentry.op': 'http.client',
                'url.full': `${PANEL}/reset-password/${MASKED}?email=${MASKED}`,
                'http.query': `?code=${MASKED}&page=2`,
                'http.request.header.referer': [`${PANEL}/servers?token=${MASKED}`],
                'server.port': 443,
                'sentry.sample_rate': { value: 0.5 },
                wrapped: { value: `${PANEL}/x?state=${MASKED}`, unit: 'none' }
            }
        })
    })

    test('a structure that points back at itself is cut off', () => {
        const loop: Record<string, unknown> = { note: 'kept' }

        loop.self = loop

        const scrubbed = scrubBreadcrumb({ category: 'custom', data: loop })

        expect(scrubbed.data?.note).toBe('kept')
        expect(JSON.stringify(scrubbed)).toContain('[Filtered]')
    })
})

test.describe('settings are read from the page', () => {
    const valid = {
        dsn: 'https://abc123@o450.ingest.sentry.io/4507',
        environment: 'production',
        release: '0.1.0',
        tracesSampleRate: 0.2
    }

    test('a complete tag is read and nothing else is kept', async () => {
        await withMeta(JSON.stringify({ ...valid, extra: 'ignored' }), () => {
            expect(sentrySettings()).toEqual(valid)
        })
    })

    test('a sample rate of zero or one is allowed', async () => {
        await withMeta(JSON.stringify({ ...valid, tracesSampleRate: 0 }), () => {
            expect(sentrySettings()?.tracesSampleRate).toBe(0)
        })
        await withMeta(JSON.stringify({ ...valid, tracesSampleRate: 1 }), () => {
            expect(sentrySettings()?.tracesSampleRate).toBe(1)
        })
    })

    const MALFORMED: [string, string | null][] = [
        ['no tag', null],
        ['an empty tag', ''],
        ['text that is not json', '{not json'],
        ['a json string', '"https://abc123@o450.ingest.sentry.io/4507"'],
        ['a json array', '[]'],
        ['a json null', 'null'],
        ['no dsn', JSON.stringify({ ...valid, dsn: undefined })],
        ['a blank dsn', JSON.stringify({ ...valid, dsn: '' })],
        ['a dsn that is not text', JSON.stringify({ ...valid, dsn: 7 })],
        ['no environment', JSON.stringify({ ...valid, environment: undefined })],
        ['no release', JSON.stringify({ ...valid, release: undefined })],
        ['a sample rate that is text', JSON.stringify({ ...valid, tracesSampleRate: '0.2' })],
        ['a sample rate below zero', JSON.stringify({ ...valid, tracesSampleRate: -0.1 })],
        ['a sample rate above one', JSON.stringify({ ...valid, tracesSampleRate: 1.5 })],
        ['no sample rate', JSON.stringify({ ...valid, tracesSampleRate: undefined })]
    ]

    for (const [name, content] of MALFORMED) {
        test(`${name} leaves no settings`, async () => {
            await withMeta(content, () => {
                expect(sentrySettings()).toBeNull()
            })
        })
    }

    test('outside a browser there are no settings', () => {
        expect(sentrySettings()).toBeNull()
    })

    test('starting without settings loads nothing', async () => {
        await withMeta(null, async () => {
            await expect(startSentry()).resolves.toBeUndefined()
        })

        expect('__SENTRY__' in globalThis).toBe(false)
    })
})

const INGEST = 'https://sentry.invalid'

type Tracking = { envelopes: string[]; violations: () => Promise<string[]> }

async function serveSentry(page: Page, meta: string | null): Promise<Tracking> {
    const envelopes: string[] = []

    await page.addInitScript(() => {
        const found: string[] = []

        ;(window as unknown as { violations: string[] }).violations = found
        document.addEventListener('securitypolicyviolation', (event) => {
            found.push(`${event.violatedDirective} ${event.blockedURI}`)
        })
    })

    await page.route('**/*', async (route) => {
        if (route.request().resourceType() !== 'document') {
            await route.fallback()

            return
        }

        const response = await route.fetch({ maxRedirects: 0 })
        const headers = response.headers()
        const policy = headers['content-security-policy']

        delete headers['content-encoding']
        delete headers['content-length']

        if (policy) {
            headers['content-security-policy'] = policy.replace("connect-src 'self'", `connect-src 'self' ${INGEST}`)
        }

        const body = (await response.text()).replace(
            '<head>',
            meta === null ? '<head>' : `<head><meta name="coresmith-sentry" content='${meta}'>`
        )

        await route.fulfill({ response, headers, body })
    })

    await page.route(`${INGEST}/**`, async (route) => {
        const body = route.request().postData()

        if (body) {
            envelopes.push(body)
        }

        await route.fulfill({
            status: route.request().method() === 'OPTIONS' ? 204 : 200,
            headers: { 'access-control-allow-origin': '*', 'access-control-allow-headers': '*' },
            contentType: 'application/json',
            body: '{}'
        })
    })

    return {
        envelopes,
        violations: () => page.evaluate(() => (window as unknown as { violations: string[] }).violations)
    }
}

function settingsFor(tracesSampleRate: number): string {
    return JSON.stringify({
        dsn: 'https://abc123@sentry.invalid/4507',
        environment: 'e2e',
        release: '0.1.0',
        tracesSampleRate
    })
}

function items(envelope: string): { type: string; payload: Record<string, any> }[] {
    const lines = envelope.split('\n').filter((line) => line !== '')
    const found: { type: string; payload: Record<string, any> }[] = []

    for (let index = 1; index < lines.length; index += 2) {
        const header = JSON.parse(lines[index])

        found.push({ type: header.type, payload: JSON.parse(lines[index + 1] ?? '{}') })
    }

    return found
}

function errorEvents(tracking: Tracking): Record<string, any>[] {
    return tracking.envelopes
        .flatMap((envelope) => items(envelope))
        .filter((item) => item.type === 'event')
        .map((item) => item.payload)
}

asGuest.describe('error tracking in the browser', () => {
    asGuest('an uncaught error reaches sentry with the reset link and the visitor masked', async ({ page }) => {
        const tracking = await serveSentry(page, settingsFor(0))

        await page.goto(`/reset-password/${TOKEN}?email=maya%40monoven.io`)
        await expect(page.getByRole('heading', { level: 1 })).toBeVisible()
        await page.waitForFunction(() => '__SENTRY__' in globalThis)
        await page.evaluate(() => {
            setTimeout(() => {
                throw new Error('The reset form could not be shown')
            }, 50)
        })

        await expect.poll(() => errorEvents(tracking).length, { timeout: 15_000 }).toBeGreaterThan(0)

        const origin = new URL(page.url()).origin
        const [event] = errorEvents(tracking)

        expect(event.request.url).toBe(`${origin}/reset-password/${MASKED}?email=${MASKED}`)
        expect(event.release).toBe('0.1.0')
        expect(event.environment).toBe('e2e')
        expect(event.exception.values[0].value).toBe('The reset form could not be shown')
        expect(event.sdk.settings.infer_ip).toBe('never')
        expect(event.user?.ip_address).toBeUndefined()
        expect(event.request.cookies).toBeUndefined()
        expect(event.request.headers['User-Agent']).toBeTruthy()
        expect(event.request.headers.Referer ?? '[Filtered]').toBe('[Filtered]')

        const everything = tracking.envelopes.join('\n')

        expect(everything).not.toContain(TOKEN)
        expect(everything).not.toContain('maya')
        expect(everything).not.toContain('monoven.io')
        expect(await tracking.violations()).toEqual([])
    })

    asGuest('breadcrumbs of the visit carry no reset link either', async ({ page }) => {
        const tracking = await serveSentry(page, settingsFor(0))

        await page.goto('/login')
        await expect(page.getByRole('heading', { level: 1 })).toBeVisible()
        await page.waitForFunction(() => '__SENTRY__' in globalThis)
        await page.evaluate(async (path) => {
            history.pushState({}, '', path)
            await fetch(path, { headers: { Accept: 'text/html' } })
            setTimeout(() => {
                throw new Error('The sign in form could not be shown')
            }, 50)
        }, `/reset-password/${TOKEN}?email=maya%40monoven.io&code=123456`)

        await expect.poll(() => errorEvents(tracking).length, { timeout: 15_000 }).toBeGreaterThan(0)

        const [event] = errorEvents(tracking)
        const crumbs: { category: string; data?: Record<string, string> }[] = event.breadcrumbs ?? []
        const navigation = crumbs.findLast((crumb) => crumb.category === 'navigation')
        const request = crumbs.findLast((crumb) => crumb.category === 'fetch')

        expect(navigation?.data?.to).toBe(`/reset-password/${MASKED}?email=${MASKED}&code=${MASKED}`)
        expect(request?.data?.url).toBe(`/reset-password/${MASKED}?email=${MASKED}&code=${MASKED}`)
        expect(tracking.envelopes.join('\n')).not.toContain(TOKEN)
        expect(tracking.envelopes.join('\n')).not.toContain('123456')
        expect(await tracking.violations()).toEqual([])
    })

    asGuest('tracing sends spans without the reset link', async ({ page }) => {
        const tracking = await serveSentry(page, settingsFor(1))

        await page.goto(`/reset-password/${TOKEN}?email=maya%40monoven.io`)
        await expect(page.getByRole('heading', { level: 1 })).toBeVisible()

        const sent = () =>
            tracking.envelopes
                .flatMap((envelope) => items(envelope))
                .filter((item) => item.type === 'span' || item.type === 'transaction')

        await expect.poll(() => sent().length, { timeout: 30_000 }).toBeGreaterThan(0)

        const everything = tracking.envelopes.join('\n')

        expect(everything).toContain(`/reset-password/${MASKED}`)
        expect(everything).not.toContain(TOKEN)
        expect(everything).not.toContain('maya')
        expect(await tracking.violations()).toEqual([])
    })

    asGuest('settings that cannot be read leave the page without error tracking', async ({ page }) => {
        const tracking = await serveSentry(page, '{"dsn": 7')

        await page.goto('/login')
        await expect(page.getByRole('heading', { level: 1 })).toBeVisible()

        expect(await page.evaluate(() => '__SENTRY__' in globalThis)).toBe(false)
        expect(tracking.envelopes).toEqual([])
    })
})
