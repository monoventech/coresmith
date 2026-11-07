import type { BadgeVariant } from '@/components/ui/badge'

export type RuntimeOption = {
    line: string
    version: string
    status: { state: string; label: string; tone: BadgeVariant }
}

export type RuntimeChoices = { label: string; recommended: string; sources: string; options: RuntimeOption[] }

export function pickerRuntime(type: string, jsRuntime: string): string | null {
    if (type === 'php' || type === 'ruby') {
        return type
    }

    if (type === 'js') {
        return jsRuntime === 'node' ? 'nodejs' : jsRuntime
    }

    return null
}
