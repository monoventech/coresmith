// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

export type LineKind = 'command' | 'phase' | 'error' | 'warning' | 'success' | 'plain'

export type Piece = { text: string; part: 'time' | 'phase' | 'body'; mark: 'match' | 'current' | null }

const PHASE = /^(\[\d{2}:\d{2}:\d{2}\]) ([a-z][a-z-]*:)(.*)$/

const ERRORS = [
    /^\s*(?:npm )?(?:ERR!|error(?::|\s|$)|ERROR(?::|\s|$)|Error(?::|\s|$)|fatal:|FATAL(?::|\s|$)|Fatal error|E: )/,
    /PHP (?:Fatal|Parse) error|SQLSTATE\[|Uncaught |Traceback \(most recent call last\)|\berror TS\d+|\berror\[E\d+\]/,
    /^\s*[✗✘×]\s/,
    /\[(?:error|ERROR)\]/,
    / FAIL$/,
    /\bexit code [1-9]\d*\b/,
    /status=\d+\/FAILURE|Failed with result '/,
    /^\[\d{2}:\d{2}:\d{2}\] deploy failed/
]

const WARNINGS = [
    /^\s*(?:npm )?(?:WARN|warn|warning|Warning|WARNING)(?::|\s|$)/,
    /^\s*W: /,
    /PHP Warning|PHP Deprecated|^\s*Deprecated:/,
    /\[(?:warn|warning|WARN|WARNING)\]/,
    /^\s*(?:⚠|\(!\))/,
    / failed \(attempt \d+ of \d+\), retrying/,
    / hit a network error \(attempt \d+ of \d+\)/,
    / failed, so Coresmith skipped it\./
]

const SUCCESSES = [
    /^\s*[✓✔]\s/,
    /^\[\d{2}:\d{2}:\d{2}\] deployed\./,
    /^\s*added \d+ packages?\b/,
    /^\s*Done in \d/,
    / DONE$/
]

export function kindOf(line: string): LineKind {
    if (line.startsWith('$ ')) {
        return 'command'
    }

    if (PHASE.test(line)) {
        return 'phase'
    }

    if (ERRORS.some((pattern) => pattern.test(line))) {
        return 'error'
    }

    if (WARNINGS.some((pattern) => pattern.test(line))) {
        return 'warning'
    }

    return SUCCESSES.some((pattern) => pattern.test(line)) ? 'success' : 'plain'
}

export function matchesIn(line: string, query: string): number[] {
    const needle = query.toLowerCase()

    if (needle === '') {
        return []
    }

    const haystack = line.toLowerCase()
    const starts: number[] = []

    for (let at = haystack.indexOf(needle); at !== -1; at = haystack.indexOf(needle, at + needle.length)) {
        starts.push(at)
    }

    return starts
}

export function pieces(line: string, query: string, current: number | null): Piece[] {
    const phase = PHASE.exec(line)
    const parts: Array<{ text: string; part: Piece['part'] }> = phase
        ? [
              { text: phase[1], part: 'time' },
              { text: ' ', part: 'body' },
              { text: phase[2], part: 'phase' },
              { text: phase[3], part: 'body' }
          ]
        : [{ text: line, part: 'body' }]
    const ranges = matchesIn(line, query).map((start) => ({
        start,
        end: start + query.length,
        mark: start === current ? ('current' as const) : ('match' as const)
    }))

    if (ranges.length === 0) {
        return parts.filter((part) => part.text !== '').map((part) => ({ ...part, mark: null }))
    }

    const result: Piece[] = []
    let offset = 0

    for (const part of parts) {
        const end = offset + part.text.length
        let at = offset

        for (const range of ranges) {
            if (range.end <= at || range.start >= end) {
                continue
            }

            if (range.start > at) {
                result.push({ text: line.slice(at, range.start), part: part.part, mark: null })
            }

            const stop = Math.min(range.end, end)
            result.push({ text: line.slice(Math.max(range.start, at), stop), part: part.part, mark: range.mark })
            at = stop
        }

        if (at < end) {
            result.push({ text: line.slice(at, end), part: part.part, mark: null })
        }

        offset = end
    }

    return result
}
