import type { Page } from '@playwright/test'
import { expect, fakeReverb } from './fixtures'

export type Shown = Record<string, unknown> & { id: string; log: string; log_bytes: number }

export function byteLength(text: string): number {
    return new TextEncoder().encode(text).length
}

export async function streamDeploys(page: Page, options: { refuse?: RegExp } = {}) {
    let shown: Shown | null = null
    let bytes = 0

    await page.route(
        (url) => /\/deployments\/[^/]+$/.test(url.pathname),
        async (route) => {
            const response = await route.fetch()

            if (!route.request().headers()['x-inertia']) {
                return route.fulfill({ response })
            }

            const visit = await response.json()
            const deployment = visit.props.deployment as Shown | undefined

            if (deployment) {
                shown = deployment
                bytes = deployment.log_bytes
            }

            await route.fulfill({ response, json: { ...visit, props: { ...visit.props, streaming: true } } })
        }
    )

    const reverb = await fakeReverb(page, options)

    async function ready(): Promise<Shown> {
        await expect
            .poll(() => shown !== null && reverb.channels.includes(`private-deployments.${shown.id}`))
            .toBe(true)

        return shown as Shown
    }

    return {
        bytes: () => bytes,
        ready,
        async push(change: (deployment: Shown) => Record<string, unknown>) {
            const deployment = await ready()

            reverb.emit(`private-deployments.${deployment.id}`, 'deployment.updated', change(deployment))
        },
        async log(offset: number, text: string) {
            const deployment = await ready()

            reverb.emit(`private-deployments.${deployment.id}`, 'deployment.log', { offset, text })
        },
        async append(text: string) {
            await ready()

            const offset = bytes

            bytes += byteLength(text)
            await this.log(offset, text)
        }
    }
}

export async function answerLog(page: Page, answer: (from: number) => string | null) {
    await page.route(
        (url) => /\/deployments\/[^/]+\/log$/.test(url.pathname),
        async (route) => {
            const response = await route.fetch()
            const delta = await response.json()
            const from = Number(new URL(route.request().url()).searchParams.get('from'))
            const text = answer(from)

            if (text === null) {
                return route.fulfill({ response, json: delta })
            }

            return route.fulfill({ response, json: { ...delta, from, text, bytes: from + byteLength(text) } })
        }
    )
}
