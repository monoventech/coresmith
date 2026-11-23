export type MovePreview = {
    team: string
    from: string
    servers: string[]
    applications: number
    databases: number
    members: number
    github: number
    reconnect: Record<string, number>
    webhooks: number
}

export type MoveCode = {
    id: string
    text: string
    until: string
    preview: MovePreview | null
}

export type Moves = {
    code: MoveCode | null
}

export type MoveAway = {
    moving: {
        since: string
        to: string
        where: string
        file: string | null
        can_cancel: boolean
    } | null
}

export type ServerMove = {
    away: boolean
    state: 'waiting' | 'running' | 'failed' | 'moved'
    error: string | null
    can_retry: boolean
    other: string
}
