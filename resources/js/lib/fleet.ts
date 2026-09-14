export type StateTone = 'default' | 'accent' | 'success' | 'warning' | 'destructive' | 'outline'

export type EntityState = {
    key: string
    label: string
    tone: StateTone
}

export type ServerCard = {
    id: string
    name: string
    state: EntityState
    meta: string
    compact_meta: string
    cpu: number | null
    memory: number | null
    disk: number | null
    href: string
    logs_href: string
    watch_href: string | null
    applications: { id: string; name: string; kind: string; deploy_href: string }[]
}

export type ActivityPart = { text: string; mono: boolean }

export type ActivityItem = {
    time: string
    at: string
    parts: ActivityPart[]
    href: string | null
}

const text: Record<StateTone, string> = {
    default: 'text-muted-foreground',
    outline: 'text-muted-foreground',
    accent: 'text-primary',
    success: 'text-success',
    warning: 'text-warning-strong',
    destructive: 'text-destructive'
}

const fill: Record<StateTone, string> = {
    default: 'bg-muted-foreground',
    outline: 'bg-muted-foreground',
    accent: 'bg-primary',
    success: 'bg-success',
    warning: 'bg-warning',
    destructive: 'bg-destructive'
}

export function toneText(tone: StateTone): string {
    return text[tone]
}

export function toneFill(tone: StateTone): string {
    return fill[tone]
}
