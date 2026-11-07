// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

const PORT = Number(process.env.E2E_STRIPE_PORT ?? 8126)
const ORIGIN = `http://127.0.0.1:${PORT}`
const DAY = 86_400

type Handler = (match: RegExpMatchArray, form: URLSearchParams) => Response

const now = (): number => Math.floor(Date.now() / 1000)
const short = (): string => crypto.randomUUID().slice(0, 8)

function json(body: unknown, status = 200): Response {
    return new Response(JSON.stringify(body), {
        status,
        headers: { 'Content-Type': 'application/json', 'Request-Id': `req_e2e_${short()}` }
    })
}

function page(title: string): Response {
    return new Response(
        `<!doctype html><html lang="en"><head><meta charset="utf-8"><title>${title}</title></head><body><h1>${title}</h1></body></html>`,
        { headers: { 'Content-Type': 'text/html' } }
    )
}

function item(id: string, price: string, subscription: string) {
    return {
        id,
        object: 'subscription_item',
        subscription,
        quantity: 1,
        current_period_start: now(),
        current_period_end: now() + 30 * DAY,
        price: {
            id: price,
            object: 'price',
            product: 'prod_e2e_cloud',
            recurring: { interval: 'month', usage_type: 'licensed' }
        }
    }
}

function subscription(id: string, form: URLSearchParams) {
    const price = form.get('items[0][price]') ?? 'price_e2e_pro'
    const itemId = form.get('items[0][id]') ?? id.replace(/^sub_/, 'si_')

    return {
        id,
        object: 'subscription',
        customer: 'cus_e2e',
        status: 'active',
        cancel_at_period_end: form.get('cancel_at_period_end') === 'true',
        trial_end: null,
        latest_invoice: null,
        default_payment_method: null,
        metadata: { type: 'default', name: 'default' },
        items: {
            object: 'list',
            has_more: false,
            url: `/v1/subscription_items?subscription=${id}`,
            data: [item(itemId, price, id)]
        }
    }
}

const routes: [string, RegExp, Handler][] = [
    ['GET', /^\/health$/, () => new Response('ok')],
    [
        'POST',
        /^\/v1\/customers$/,
        (_, form) =>
            json({
                id: `cus_e2e_${short()}`,
                object: 'customer',
                name: form.get('name'),
                email: form.get('email'),
                invoice_settings: { default_payment_method: null }
            })
    ],
    [
        'GET',
        /^\/v1\/customers\/([^/]+)$/,
        (match) => json({ id: match[1], object: 'customer', invoice_settings: { default_payment_method: null } })
    ],
    [
        'POST',
        /^\/v1\/checkout\/sessions$/,
        (_, form) => {
            const id = `cs_e2e_${short()}`

            return json({
                id,
                object: 'checkout.session',
                mode: 'subscription',
                status: 'open',
                customer: form.get('customer'),
                url: `${ORIGIN}/checkout/${id}`
            })
        }
    ],
    [
        'POST',
        /^\/v1\/checkout\/sessions\/([^/]+)\/expire$/,
        (match) => json({ id: match[1], object: 'checkout.session', status: 'expired' })
    ],
    [
        'POST',
        /^\/v1\/billing_portal\/sessions$/,
        (_, form) =>
            json({
                id: `bps_e2e_${short()}`,
                object: 'billing_portal.session',
                url: `${ORIGIN}/portal/${form.get('customer') ?? 'unknown'}`
            })
    ],
    ['GET', /^\/v1\/subscriptions\/([^/]+)$/, (match, form) => json(subscription(match[1], form))],
    ['POST', /^\/v1\/subscriptions\/([^/]+)$/, (match, form) => json(subscription(match[1], form))],
    ['GET', /^\/v1\/subscription_items\/([^/]+)$/, (match) => json(item(match[1], 'price_e2e_pro', 'sub_e2e'))],
    ['GET', /^\/checkout\/.+$/, () => page('Stripe Checkout (test double)')],
    ['GET', /^\/portal\/.+$/, () => page('Stripe billing portal (test double)')]
]

Bun.serve({
    port: PORT,
    hostname: '127.0.0.1',
    async fetch(request) {
        const url = new URL(request.url)
        const form = new URLSearchParams(request.method === 'POST' ? await request.text() : url.search)

        for (const [method, pattern, handle] of routes) {
            const match = url.pathname.match(pattern)

            if (match && method === request.method) {
                return handle(match, form)
            }
        }

        return json(
            {
                error: {
                    type: 'invalid_request_error',
                    message: `The e2e Stripe double does not answer ${request.method} ${url.pathname}.`
                }
            },
            404
        )
    }
})
