export type DnsRecord = { type: string; name: string; value: string; note?: string | null }

export type DnsTarget = { type: string; value: string; closed: boolean }

export type DnsRow = { type: string; name?: string | null; value: string; closed?: boolean; note?: string | null }

export function namedTargets(targets: DnsTarget[], domain: string): DnsRow[] {
    const name = domain.trim().toLowerCase() || null

    return targets.map((target) => ({ ...target, name }))
}
