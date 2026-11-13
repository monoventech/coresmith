// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs'
import { codeHash } from './codeHash'
import type { Result } from './run'

type Classified = { name: string; commit?: string; cause: 'stack' | 'app'; why: string }

const files = process.argv.slice(2).filter((argument) => !argument.startsWith('--'))
const gate = process.argv.includes('--gate')

if (files.length === 0) {
    console.error('Usage: bun tests/Corpus/report.ts results.jsonl [more.jsonl] [--gate]')
    process.exit(2)
}

const latest = new Map<string, Result>()

for (const file of files) {
    for (const line of readFileSync(file, 'utf8').split('\n')) {
        if (line.trim() !== '') {
            const result = JSON.parse(line) as Result
            latest.set(result.name, result)
        }
    }
}

const classified = new Map<string, Classified>(
    (existsSync('tests/Corpus/classified.json')
        ? (JSON.parse(readFileSync('tests/Corpus/classified.json', 'utf8')) as Classified[])
        : []
    ).map((entry) => [entry.name, entry])
)
const results = [...latest.values()]
const ready = results.filter((result) => result.verdict === 'ready')
const deployed = ready.filter((result) => result.deploy !== null)
const passed = deployed.filter(
    (result) => result.deploy?.status === 'Deployed' && !/\b5\d\d\b/.test(result.deploy?.live ?? '')
)
const failed = deployed.filter((result) => !passed.includes(result))
const app = failed.filter((result) => classified.get(result.name)?.cause === 'app')
const unclassified = failed.filter((result) => !classified.has(result.name))
const expected = results.filter((result) => result.matched !== null)
const unmatched = expected.filter((result) => result.matched === false)
const percent = (part: number, whole: number): string => (whole === 0 ? 'n/a' : `${((100 * part) / whole).toFixed(1)}%`)

const lines = [
    `Repositories: ${results.length}`,
    `Coverage, filled in completely: ${ready.length} (${percent(ready.length, results.length)})`,
    `Deployed with the filled-in stack: ${passed.length} of ${deployed.length} (strict ${percent(passed.length, deployed.length)})`,
    `The bar, leaving out failures no stack choice could fix: ${percent(passed.length, deployed.length - app.length)} (${app.length} left out)`,
    `Verdicts: ${['ready', 'unsure', 'blocked', 'unreadable', 'failed', 'needs_key'].map((verdict) => `${verdict} ${results.filter((result) => result.verdict === verdict).length}`).join(', ')}`,
    ...(expected.length > 0
        ? [`Fixed set: ${expected.length - unmatched.length} of ${expected.length} as expected`]
        : []),
    '',
    ...failed.map((result) => {
        const cause = classified.get(result.name)

        return `- ${result.name} ${result.url} (${result.deploy?.status}) ${cause === undefined ? 'UNCLASSIFIED' : `${cause.cause}: ${cause.why}`}\n  ${(result.deploy?.reason ?? result.deploy?.live ?? '').replaceAll('\n', ' ').slice(0, 240)}`
    }),
    ...unmatched.map(
        (result) =>
            `- NOT AS EXPECTED: ${result.name} got ${result.verdict} ${JSON.stringify(result.fields)}: ${result.detail.replaceAll('\n', ' ').slice(0, 200)}`
    )
]

console.log(lines.join('\n'))

if (gate) {
    if (expected.length === 0 || unmatched.length > 0 || unclassified.length > 0) {
        console.error('The fixed set did not pass, so no gate file was written.')
        process.exit(1)
    }

    mkdirSync('storage/corpus', { recursive: true })
    writeFileSync(
        `storage/corpus/fixed-pass-${codeHash()}.json`,
        JSON.stringify({ at: new Date().toISOString(), results: results.length }) + '\n'
    )
    console.log(`Gate written for code ${codeHash()}.`)
}
