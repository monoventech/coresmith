import type { Page, TestInfo } from '@playwright/test'
import {
    asDeveloper,
    asMember,
    blockingViolations,
    dialog,
    expect,
    fakeReverb,
    gotoServer,
    isMobile,
    own,
    test,
    useTheme,
    withProps
} from './support/fixtures'

test.use({ reducedMotion: 'reduce' })

type Sent = { input: string[]; checkIns: Array<{ keep: boolean; hidden: boolean }> }

function code(testInfo: TestInfo): string {
    return own(testInfo, '', '')
}

function sessionUrl(testInfo: TestInfo): string {
    return `/servers/${own(testInfo, 'shell')}/terminal/01kv${'0'.repeat(19)}${code(testInfo)}`
}

function base64(text: string): string {
    return Buffer.from(text, 'utf8').toString('base64')
}

async function openSession(page: Page, testInfo: TestInfo) {
    const sent: Sent = { input: [], checkIns: [] }
    const reverb = await fakeReverb(page)
    const channel = `private-terminals.01kv${'0'.repeat(19)}${code(testInfo)}`

    await page.route(/\/terminal\/[^/]+\/screen$/, (route) =>
        route.fulfill({ json: { seq: 1, screen: base64('root@shell:~# '), status: 'open', ended: null } })
    )
    await page.route(/\/terminal\/[^/]+\/input$/, async (route) => {
        const body = route.request().postDataJSON() as { data: string }

        sent.input.push(Buffer.from(body.data, 'base64').toString('utf8'))
        await route.fulfill({ json: { status: 'open', ended: null } })
    })
    await page.route(/\/terminal\/[^/]+\/check-in$/, async (route) => {
        sent.checkIns.push(route.request().postDataJSON() as { keep: boolean; hidden: boolean })
        await route.fulfill({ json: { status: 'open', ended: null } })
    })
    await page.route(/\/terminal\/[^/]+\/resize$/, (route) => route.fulfill({ json: { status: 'open', ended: null } }))

    await page.goto(sessionUrl(testInfo))
    await expect(page.getByTestId('terminal-session')).toBeVisible()
    await expect.poll(() => reverb.channels.includes(channel)).toBe(true)
    await expect(page.getByTestId('terminal-status')).toContainText('Connected')

    let seq = 1

    return {
        sent,
        rows: page.locator('.xterm-rows'),
        print(text: string) {
            seq++
            reverb.emit(channel, 'terminal.output', { seq, data: base64(text) })
        },
        lose() {
            seq++
        },
        warn(reason: 'idle' | 'hour', inSeconds: number) {
            reverb.emit(channel, 'terminal.warning', { reason, ends_at: Math.floor(Date.now() / 1000) + inSeconds })
        }
    }
}

test.describe('a terminal session', () => {
    test('a page opened before the shell started says Connected once the shell answers', async ({ page }, testInfo) => {
        const opened = Math.floor(Date.now() / 1000) - 5
        const answer = { status: 'open', started_at: opened, ended: null }
        const reverb = await fakeReverb(page)
        const channel = `private-terminals.01kv${'0'.repeat(19)}${code(testInfo)}`

        await withProps(page, /\/terminal\/[^/]+$/, (props) => {
            Object.assign(props.terminal as Record<string, unknown>, { status: 'starting', started_at: null })
        })
        await page.route(/\/terminal\/[^/]+\/screen$/, (route) =>
            route.fulfill({ json: { seq: 1, screen: base64('root@shell:~# '), ...answer } })
        )
        await page.route(/\/terminal\/[^/]+\/(check-in|resize|input)$/, (route) => route.fulfill({ json: answer }))

        await page.goto(sessionUrl(testInfo))
        await expect.poll(() => reverb.channels.includes(channel)).toBe(true)
        await expect(page.getByTestId('terminal-status')).toContainText(/Connected · \d+:\d\d/)
    })

    test('output appears in order, and typing reaches the shell', async ({ page }, testInfo) => {
        const shell = await openSession(page, testInfo)

        shell.print('hello from the shell\r\n')
        shell.print('root@shell:~# ')

        await expect(shell.rows).toContainText('hello from the shell')

        await page.getByTestId('terminal-shell').click()
        await page.keyboard.type('ls -la')
        await page.keyboard.press('Enter')

        await expect.poll(() => shell.sent.input.join('')).toBe('ls -la\r')
    })

    test('output lost on the way comes back from the saved screen, even when the first read is older', async ({
        page
    }, testInfo) => {
        const shell = await openSession(page, testInfo)
        let reads = 0

        await page.route(/\/terminal\/[^/]+\/screen$/, (route) => {
            reads++

            return route.fulfill({
                json:
                    reads === 1
                        ? { seq: 1, screen: base64('root@shell:~# '), status: 'open', ended: null }
                        : {
                              seq: 4,
                              screen: base64('root@shell:~# one\r\ntwo\r\nthree\r\n'),
                              status: 'open',
                              ended: null
                          }
            })
        })

        shell.print('one\r\n')
        shell.lose()
        shell.print('three\r\n')

        await expect(shell.rows).toContainText('two')
        await expect(shell.rows).toContainText('three')
        expect(reads).toBeGreaterThanOrEqual(2)
    })

    test('a paste of several lines asks first, and goes in once you agree', async ({ page }, testInfo) => {
        const shell = await openSession(page, testInfo)

        await page.getByTestId('terminal-shell').evaluate((element) => {
            const data = new DataTransfer()
            const paste = new Event('paste', { bubbles: true, cancelable: true })

            data.setData('text/plain', 'echo one\necho two\necho three\n')
            Object.defineProperty(paste, 'clipboardData', { value: data })
            element.querySelector('textarea')?.dispatchEvent(paste)
        })

        await expect(dialog(page).getByRole('heading', { name: 'Paste 3 lines into the shell?' })).toBeVisible()
        await expect(dialog(page).getByTestId('terminal-paste-preview')).toContainText('echo two')
        expect(shell.sent.input).toEqual([])

        await dialog(page).getByRole('button', { name: 'Paste' }).click()

        await expect.poll(() => shell.sent.input.join('')).toContain('echo one\recho two\recho three')
    })

    test('a warning before the session ends, and Keep it open counts as typing', async ({ page }, testInfo) => {
        const shell = await openSession(page, testInfo)

        shell.warn('idle', 75)

        const warning = page.getByTestId('terminal-warning')

        await expect(warning).toContainText('Nothing typed for 9 minutes, so this session ends in 1 minute.')
        await warning.getByRole('button', { name: 'Keep it open' }).click()

        await expect(warning).toHaveCount(0)
        await expect.poll(() => shell.sent.checkIns.some((checkIn) => checkIn.keep)).toBe(true)
    })

    test('Find looks through the output and says when nothing matches', async ({ page }, testInfo) => {
        const shell = await openSession(page, testInfo)

        shell.print('needle in the haystack\r\n')
        await expect(shell.rows).toContainText('needle in the haystack')

        await page.getByRole('button', { name: 'Find' }).click()
        await page.getByLabel('Find in the terminal').fill('needle')
        await expect(page.getByTestId('terminal-find')).not.toContainText('No match')

        await page.getByLabel('Find in the terminal').fill('zebra')
        await expect(page.getByTestId('terminal-find')).toContainText('No match')
    })

    test('ending the session asks first, then shows what changed', async ({ page }, testInfo) => {
        await openSession(page, testInfo)
        await page.route(/\/terminal\/[^/]+$/, (route) =>
            route.request().method() === 'DELETE'
                ? route.fulfill({
                      json: {
                          status: 'closed',
                          ended: {
                              reason: 'ended',
                              sentence: 'You ended it.',
                              minutes: 23,
                              checking: false,
                              opened: true,
                              changes: [
                                  {
                                      key: 'firewall',
                                      title: 'The firewall rules',
                                      detail: "The firewall rules changed. The server's Hardening tab shows what's open now, and can put Coresmith's rules back."
                                  }
                              ]
                          }
                      }
                  })
                : route.continue()
        )

        await page.getByTestId('terminal-end').click()
        await expect(dialog(page).getByRole('heading', { name: 'End this session?' })).toBeVisible()
        await dialog(page).getByTestId('terminal-end-confirm').click()

        const summary = page.getByTestId('terminal-summary')

        await expect(summary.getByRole('heading', { level: 1, name: 'Session ended' })).toBeVisible()
        await expect(summary).toContainText('You ended it. It ran for 23 minutes.')
        await expect(summary.getByTestId('terminal-changes')).toContainText('The firewall rules')
        await expect(summary.getByRole('link', { name: 'Go to Hardening' })).toBeVisible()
        await expect(summary.getByRole('link', { name: 'Open a new session' })).toBeVisible()
    })

    test('phones get a row of keys a phone keyboard lacks', async ({ page }, testInfo) => {
        const shell = await openSession(page, testInfo)
        const keys = page.getByTestId('terminal-keys')

        if (!isMobile(testInfo)) {
            await expect(keys).toBeHidden()

            return
        }

        await keys.getByRole('button', { name: 'Esc' }).click()
        await keys.getByRole('button', { name: 'Up' }).click()
        await keys.getByRole('button', { name: 'Tab' }).click()

        await expect.poll(() => shell.sent.input.join('')).toBe('\x1b\x1b[A\t')
    })

    for (const theme of ['light', 'dark'] as const) {
        test(`the session has no serious or critical accessibility violations in ${theme}`, async ({
            page
        }, testInfo) => {
            await useTheme(page, theme)
            const shell = await openSession(page, testInfo)

            shell.warn('hour', 300)
            await expect(page.getByTestId('terminal-warning')).toBeVisible()

            expect(await blockingViolations(page)).toEqual([])
        })
    }
})

test.describe('opening a terminal', () => {
    async function startPanel(page: Page) {
        await withProps(page, /\/servers\/[\w.-]+\/terminal$/, (props) => {
            props.unavailable = null
        })
        await gotoServer(page, 'forge-02')
        await page.getByTestId('server-terminal').click()
        await page.waitForURL(/\/terminal$/)
        await expect(page.getByRole('heading', { level: 1, name: 'Open a terminal' })).toBeVisible()
    }

    test('an owner chooses root or an app, and gives the password', async ({ page }) => {
        await startPanel(page)

        await expect(page.getByTestId('terminal-mode-root')).toContainText('Full control of the server.')
        await page.getByTestId('terminal-mode-app').click()
        await expect(page.getByLabel('App', { exact: true })).toBeVisible()
        await expect(page.getByLabel('Your password')).toBeVisible()
        await expect(page.getByTestId('terminal-open')).toBeEnabled()
    })

    test("a server's own terminal page sends you back to the session you have open there", async ({
        page
    }, testInfo) => {
        await page.goto(`/servers/${own(testInfo, 'shell')}/terminal`)

        await page.waitForURL(new RegExp(`/terminal/01kv0+${code(testInfo)}$`))
        await expect(page.getByTestId('terminal-session')).toBeVisible()
    })

    for (const theme of ['light', 'dark'] as const) {
        test(`the start panel has no serious or critical accessibility violations in ${theme}`, async ({ page }) => {
            await useTheme(page, theme)
            await startPanel(page)

            expect(await blockingViolations(page)).toEqual([])
        })
    }
})

asDeveloper.describe('a terminal for a developer', () => {
    asDeveloper('a developer gets no terminal on a server someone else added', async ({ page }) => {
        await gotoServer(page, 'forge-02')

        await expect(page.getByTestId('server-terminal')).toHaveCount(0)
    })
})

asMember.describe('a terminal for a deployer', () => {
    asMember('a deployer gets no terminal at all', async ({ page }) => {
        await gotoServer(page, 'forge-01')

        await expect(page.getByTestId('server-terminal')).toHaveCount(0)
    })
})
