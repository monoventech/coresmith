const fetchJson = async <T>(url: string): Promise<T> => {
    const response = await fetch(url, {
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        }
    })

    if (!response.ok) {
        throw new Error(`Failed to fetch: ${response.status}`)
    }

    const contentType = response.headers.get('content-type') || ''

    if (contentType.includes('application/json')) {
        return response.json()
    }

    const text = await response.text()

    try {
        return JSON.parse(text) as T
    } catch {
        return text as T
    }
}

export function createTwoFactorAuth() {
    let errors = $state<string[]>([])
    let manualSetupKey = $state<string | null>(null)
    let qrCodeSvg = $state<string | null>(null)
    let recoveryCodesList = $state<string[]>([])

    const hasSetupData = $derived(qrCodeSvg !== null && manualSetupKey !== null)

    async function fetchQrCode(): Promise<void> {
        try {
            const { svg } = await fetchJson<{ svg: string; url: string }>(route('two-factor.qr-code'))

            qrCodeSvg = svg
        } catch {
            errors = [...errors, 'Failed to fetch QR code']
            qrCodeSvg = null
        }
    }

    async function fetchSetupKey(): Promise<void> {
        try {
            const payload = await fetchJson<{ secretKey?: string; secret_key?: string } | string>(
                route('two-factor.secret-key')
            )

            const key = typeof payload === 'string' ? payload : (payload.secretKey ?? payload.secret_key ?? null)

            if (!key) {
                throw new Error('Setup key not found in response')
            }

            manualSetupKey = key
        } catch {
            errors = [...errors, 'Failed to fetch a setup key']
            manualSetupKey = null
        }
    }

    function clearSetupData(): void {
        manualSetupKey = null
        qrCodeSvg = null
        clearErrors()
    }

    function clearErrors(): void {
        errors = []
    }

    function clearTwoFactorAuthData(): void {
        clearSetupData()
        clearErrors()
        recoveryCodesList = []
    }

    async function fetchRecoveryCodes(): Promise<void> {
        try {
            clearErrors()
            recoveryCodesList = await fetchJson<string[]>(route('two-factor.recovery-codes'))
        } catch {
            errors = [...errors, 'Failed to fetch recovery codes']
            recoveryCodesList = []
        }
    }

    async function fetchSetupData(): Promise<void> {
        try {
            clearErrors()
            await Promise.all([fetchQrCode(), fetchSetupKey()])
        } catch {
            qrCodeSvg = null
            manualSetupKey = null
        }
    }

    return {
        get errors() {
            return errors
        },
        get manualSetupKey() {
            return manualSetupKey
        },
        get qrCodeSvg() {
            return qrCodeSvg
        },
        get recoveryCodesList() {
            return recoveryCodesList
        },
        get hasSetupData() {
            return hasSetupData
        },
        clearSetupData,
        clearErrors,
        clearTwoFactorAuthData,
        fetchQrCode,
        fetchSetupKey,
        fetchSetupData,
        fetchRecoveryCodes
    }
}
