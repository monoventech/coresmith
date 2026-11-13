// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

import { execFileSync } from 'node:child_process'
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs'
import { parseArgs } from 'node:util'

type Quota = { stack: string; query: string; file: string; count: number }

const QUOTAS: Quota[] = [
    { stack: 'laravel', query: '"laravel/framework" filename:composer.json', file: 'composer.json', count: 60 },
    { stack: 'symfony', query: '"symfony/framework-bundle" filename:composer.json', file: 'composer.json', count: 10 },
    { stack: 'php', query: '"slim/slim" filename:composer.json', file: 'composer.json', count: 10 },
    { stack: 'sveltekit', query: '"@sveltejs/kit" filename:package.json', file: 'package.json', count: 30 },
    { stack: 'nextjs', query: '"next build" filename:package.json', file: 'package.json', count: 40 },
    { stack: 'nuxt', query: '"nuxt build" filename:package.json', file: 'package.json', count: 25 },
    { stack: 'astro', query: '"astro build" filename:package.json', file: 'package.json', count: 25 },
    { stack: 'node', query: '"express" "node" "start" filename:package.json', file: 'package.json', count: 30 },
    { stack: 'rails', query: '"railties" filename:Gemfile.lock', file: 'Gemfile.lock', count: 40 },
    { stack: 'sinatra', query: '"sinatra" filename:Gemfile.lock', file: 'Gemfile.lock', count: 15 },
    { stack: 'static', query: '"<!DOCTYPE html>" filename:index.html', file: 'index.html', count: 15 }
]

const PAGES = 5

const { values } = parseArgs({
    options: {
        seed: { type: 'string', default: '1' },
        out: { type: 'string' },
        scale: { type: 'string', default: '1' },
        exclude: { type: 'string', multiple: true }
    }
})
const seed = Number(values.seed)
const since = new Date(Date.now() - 548 * 24 * 3600 * 1000).toISOString()

function random(start: number): () => number {
    let state = start >>> 0

    return () => {
        state = (state + 0x6d2b79f5) >>> 0
        let mixed = Math.imul(state ^ (state >>> 15), 1 | state)
        mixed = (mixed + Math.imul(mixed ^ (mixed >>> 7), 61 | mixed)) ^ mixed

        return ((mixed ^ (mixed >>> 14)) >>> 0) / 4294967296
    }
}

function gh(args: string[]): string {
    return execFileSync('gh', args, { encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] })
}

function pause(seconds: number): void {
    execFileSync('sleep', [String(seconds)])
}

const next = random(seed)
const chosen = new Set<string>(
    (values.exclude ?? []).flatMap((file) =>
        (JSON.parse(readFileSync(file, 'utf8')) as { name: string }[]).map((entry) => entry.name)
    )
)
const scale = Number(values.scale)
const entries: { name: string; url: string; branch: string; stack: string }[] = []

for (const quota of QUOTAS) {
    const found = new Set<string>()

    for (let page = 1; page <= PAGES; page++) {
        try {
            gh([
                'api',
                '-X',
                'GET',
                'search/code',
                '-f',
                `q=${quota.query}`,
                '-f',
                'per_page=100',
                '-f',
                `page=${page}`,
                '--jq',
                `.items[] | select(.path == "${quota.file}") | .repository.full_name`
            ])
                .split('\n')
                .filter((name) => name !== '')
                .forEach((name) => found.add(name))
        } catch {
            break
        }

        pause(7)
    }

    const candidates = [...found]
        .map((name) => ({ name, order: next() }))
        .sort((a, b) => a.order - b.order)
        .map((candidate) => candidate.name)
    let taken = 0

    for (const name of candidates) {
        if (taken >= Math.round(quota.count * scale)) {
            break
        }

        if (chosen.has(name)) {
            continue
        }

        try {
            const [fork, archived, size, pushed, branch] = gh([
                'api',
                `repos/${name}`,
                '--jq',
                '[.fork, .archived, .size, .pushed_at, .default_branch] | @tsv'
            ])
                .trim()
                .split('\t')

            if (fork === 'false' && archived === 'false' && Number(size) < 100_000 && pushed > since) {
                chosen.add(name)
                entries.push({ name, url: `https://github.com/${name}.git`, branch, stack: quota.stack })
                taken++
            }
        } catch {
            continue
        }
    }

    console.log(`${quota.stack}: ${taken} of ${Math.round(quota.count * scale)} from ${found.size} found`)
}

const out = values.out ?? `storage/corpus/sample-${seed}.json`

mkdirSync('storage/corpus', { recursive: true })
writeFileSync(out, JSON.stringify(entries, null, 4) + '\n')
console.log(`${entries.length} repositories in ${out}`)
