// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.
const feed = Bun.file(new URL('../fixtures/release-feed.json', import.meta.url))

Bun.serve({
    port: 8125,
    hostname: '127.0.0.1',
    fetch(request) {
        if (new URL(request.url).pathname === '/v1/version') {
            return new Response(feed, { headers: { 'Content-Type': 'application/json' } })
        }

        return new Response('Not found', { status: 404 })
    }
})
