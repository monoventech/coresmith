// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

import type { Breadcrumb, BrowserOptions, Event as SentryEvent } from '@sentry/svelte'

export type SentrySettings = { dsn: string; environment: string; release: string; tracesSampleRate: number }

const URL_MASK = '%5BFiltered%5D'

const TEXT_MASK = '[Filtered]'

const MAX_DEPTH = 8

const KEPT_MAILBOXES = ['git']

const ADDRESS_KEY = /url|href|query|path|file|fragment|refer|(?:^|[._-])(?:from|to|target)$/i

const SECRET_KEY =
    /password|passwd|secret|token|api[_-]?key|authorization|cookie|session|private[_ -]?key|credential|signature|email|otp|recovery_codes?/i

const EMAIL =
    /(?<![A-Za-z0-9._%+\-\u0080-\uFFFF]|[A-Za-z0-9._%+\-\u0080-\uFFFF]')([A-Za-z0-9._%+\-\u0080-\uFFFF][A-Za-z0-9._%+\-'\u0080-\uFFFF]{0,127})(?:@|%40)(?:[A-Za-z0-9\-\u0080-\uFFFF]{1,63}\.){1,8}[A-Za-z\u0080-\uFFFF]{2,24}/g

const RULES = {
    address: {
        reset: /(\/reset-password\/)[^/?#]+/gi,
        verify: /(\/(?:verify-email|email\/verify)\/[^/?#]+\/)[^/?#]+/gi,
        query: /(^|[?&]|&amp;)((?:token|code|state|signature|email)|[^=&#]{0,64}signature)(\[[^\]=&#]{0,32}\]|%5B[^=&#]{0,32}?%5D)?=[^&#]*/gi
    },
    text: {
        reset: /(\/reset-password\/)[^/?#\s"'<>)]+/gi,
        verify: /(\/(?:verify-email|email\/verify)\/[^/?#\s"'<>)]+\/)[^/?#\s"'<>)]+/gi,
        query: /([?&]|&amp;)((?:token|code|state|signature|email)|[^=&#\s"'<>)]{0,64}signature)(\[[^\]=&#]{0,32}\]|%5B[^=&#]{0,32}?%5D)?=[^&#\s"'<>)]*/gi
    }
}

const DATA_COLLECTION: NonNullable<BrowserOptions['dataCollection']> = {
    userInfo: false,
    cookies: false,
    httpHeaders: { request: { allow: ['user-agent'] }, response: false },
    httpBodies: []
}

export function sentrySettings(): SentrySettings | null {
    if (typeof document === 'undefined') {
        return null
    }

    const content = document.querySelector('meta[name="coresmith-sentry"]')?.getAttribute('content')

    if (!content) {
        return null
    }

    try {
        return settingsFrom(JSON.parse(content))
    } catch {
        return null
    }
}

function settingsFrom(value: unknown): SentrySettings | null {
    if (typeof value !== 'object' || value === null || Array.isArray(value)) {
        return null
    }

    const { dsn, environment, release, tracesSampleRate } = value as Record<string, unknown>

    if (typeof dsn !== 'string' || dsn === '' || typeof environment !== 'string' || typeof release !== 'string') {
        return null
    }

    if (typeof tracesSampleRate !== 'number' || !(tracesSampleRate >= 0 && tracesSampleRate <= 1)) {
        return null
    }

    return { dsn, environment, release, tracesSampleRate }
}

function mask(subject: string, text: boolean): string {
    const rules = text ? RULES.text : RULES.address
    const email = text ? TEXT_MASK : URL_MASK

    return subject
        .replace(EMAIL, (found, mailbox: string) => (KEPT_MAILBOXES.includes(mailbox.toLowerCase()) ? found : email))
        .replace(rules.reset, `$1${URL_MASK}`)
        .replace(rules.verify, `$1${URL_MASK}`)
        .replace(rules.query, `$1$2$3=${URL_MASK}`)
}

export function filterUrl(url: string): string {
    return mask(url, false)
}

export function filterText(text: string): string {
    return mask(text, true)
}

function isPlain(value: object): boolean {
    const prototype = Object.getPrototypeOf(value)

    return prototype === Object.prototype || prototype === null
}

function scrub(value: unknown, key: string, depth: number): unknown {
    if (value === undefined || value === null) {
        return value
    }

    if (SECRET_KEY.test(key)) {
        return TEXT_MASK
    }

    if (typeof value === 'string') {
        return ADDRESS_KEY.test(key) ? filterUrl(value) : filterText(value)
    }

    if (typeof value !== 'object') {
        return value
    }

    if (depth >= MAX_DEPTH || (!Array.isArray(value) && !isPlain(value))) {
        return TEXT_MASK
    }

    if (Array.isArray(value)) {
        return value.map((item) => scrub(item, key, depth + 1))
    }

    return Object.fromEntries(
        Object.entries(value).map(([name, item]) => [name, scrub(item, name === 'value' ? key : name, depth + 1)])
    )
}

function scrubTrees<T extends object>(value: T, trees: string[]): T {
    const scrubbed = { ...value } as Record<string, unknown>

    for (const tree of trees) {
        if (scrubbed[tree]) {
            scrubbed[tree] = scrub(scrubbed[tree], tree, 0)
        }
    }

    return scrubbed as T
}

export function scrubEvent<T extends SentryEvent>(event: T): T {
    const scrubbed = scrubTrees(event, ['request', 'exception', 'contexts', 'extra', 'tags'])

    if (typeof event.transaction === 'string') {
        scrubbed.transaction = filterUrl(event.transaction)
    }

    if (typeof event.message === 'string') {
        scrubbed.message = filterText(event.message)
    }

    return scrubbed
}

export function scrubBreadcrumb<T extends Breadcrumb>(breadcrumb: T): T {
    const scrubbed = scrubTrees(breadcrumb, ['data'])

    if (typeof breadcrumb.message === 'string') {
        scrubbed.message = filterText(breadcrumb.message)
    }

    return scrubbed
}

export function scrubSpan<T extends { name: string; attributes?: unknown }>(span: T): T {
    const scrubbed = scrubTrees(span, ['attributes'])

    if (typeof span.name === 'string') {
        scrubbed.name = filterUrl(span.name)
    }

    return scrubbed
}

export async function startSentry(): Promise<void> {
    const settings = sentrySettings()

    if (settings === null) {
        return
    }

    try {
        const { browserTracingIntegration, init } = await import('@sentry/svelte')

        init({
            dsn: settings.dsn,
            environment: settings.environment,
            release: settings.release,
            dataCollection: DATA_COLLECTION,
            tracesSampleRate: settings.tracesSampleRate,
            traceLifecycle: 'stream',
            integrations: settings.tracesSampleRate > 0 ? [browserTracingIntegration()] : [],
            beforeSend: scrubEvent,
            beforeBreadcrumb: scrubBreadcrumb,
            beforeSendSpan: scrubSpan
        })
    } catch {
        return
    }
}
