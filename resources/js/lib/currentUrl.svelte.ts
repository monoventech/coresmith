import { toUrl } from '@/lib/utils'
import type { LinkComponentBaseProps } from '@inertiajs/core'
import { page } from '@inertiajs/svelte'

export type CurrentUrlState = {
    readonly currentUrl: string
    isCurrentUrl: (urlToCheck: NonNullable<LinkComponentBaseProps['href']>, currentUrl: string) => boolean
    isCurrentOrParentUrl: (urlToCheck: NonNullable<LinkComponentBaseProps['href']>, currentUrl: string) => boolean
    whenCurrentUrl: <TIfTrue, TIfFalse>(
        urlToCheck: NonNullable<LinkComponentBaseProps['href']>,
        currentUrl: string,
        ifTrue: TIfTrue,
        ifFalse: TIfFalse
    ) => TIfTrue | TIfFalse
}

function toPathname(value: string): string {
    const origin = typeof window === 'undefined' ? 'http://localhost' : window.location.origin

    try {
        return new URL(value, origin).pathname
    } catch {
        return value
    }
}

export function currentUrlState(): CurrentUrlState {
    const currentUrl = $derived.by(() => toPathname(page.url))

    function isCurrentUrl(urlToCheck: NonNullable<LinkComponentBaseProps['href']>, current: string): boolean {
        const resolved = toUrl(urlToCheck)

        if (typeof resolved !== 'string') {
            return false
        }

        return toPathname(current) === toPathname(resolved)
    }

    function isCurrentOrParentUrl(urlToCheck: NonNullable<LinkComponentBaseProps['href']>, current: string): boolean {
        const resolved = toUrl(urlToCheck)

        if (typeof resolved !== 'string') {
            return false
        }

        return toPathname(current).startsWith(toPathname(resolved))
    }

    function whenCurrentUrl<TIfTrue, TIfFalse>(
        urlToCheck: NonNullable<LinkComponentBaseProps['href']>,
        current: string,
        ifTrue: TIfTrue,
        ifFalse: TIfFalse
    ): TIfTrue | TIfFalse {
        return isCurrentUrl(urlToCheck, current) ? ifTrue : ifFalse
    }

    return {
        get currentUrl() {
            return currentUrl
        },
        isCurrentUrl,
        isCurrentOrParentUrl,
        whenCurrentUrl
    }
}
