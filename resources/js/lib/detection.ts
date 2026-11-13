// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

export type DetectionBlocker = { field: string; reason: string; fix: string }

export type DetectionFields = {
    type: string | null
    framework: string | null
    js_runtime: string | null
    web_directory: string | null
    version: string | null
}

export type DetectionRead = {
    state: 'read'
    id: string
    commit: string | null
    verdict: 'ready' | 'unsure' | 'blocked'
    fields: DetectionFields
    sources: Record<string, string>
    unsettled: Record<string, string>
    blockers: DetectionBlocker[]
}

export type Detection =
    | DetectionRead
    | { state: 'failed'; id?: string; failure: string; definitive: boolean }
    | { state: 'needs_key' }
    | { state: 'server_not_ready'; server: string }

export type DetectionRequest = {
    repository_url?: string
    source_provider_id?: string
    repository_id?: string
    repository_branch: string
}

const HEADERS = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }

export const TOO_MANY = 'Coresmith has read 20 repositories in the last minute. Wait a moment, then try again.'

export const UNANSWERED =
    "Coresmith didn't answer while reading the repository. Check your connection, then change the branch to try again."

export async function detectStack(href: string, request: DetectionRequest, signal: AbortSignal): Promise<Detection> {
    const query = new URLSearchParams(
        Object.entries(request).filter((entry): entry is [string, string] => typeof entry[1] === 'string')
    )

    try {
        const response = await fetch(`${href}?${query.toString()}`, { headers: HEADERS, signal })

        if (response.status === 429) {
            return { state: 'failed', failure: TOO_MANY, definitive: false }
        }

        if (!response.ok) {
            return { state: 'failed', failure: UNANSWERED, definitive: false }
        }

        return (await response.json()) as Detection
    } catch {
        return { state: 'failed', failure: UNANSWERED, definitive: false }
    }
}

export function detectionId(detection: Detection | null): string {
    return detection !== null && (detection.state === 'read' || detection.state === 'failed')
        ? (detection.id ?? '')
        : ''
}
