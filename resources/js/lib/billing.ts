// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

export type BillingNotes = {
    members: string | null
    custom_domain: string | null
    single_sign_on: string | null
    offsite_backups: string | null
    audit_log_export: string | null
}

export type BillingBannerProps = {
    tone: 'warning' | 'destructive'
    title: string
    body: string
    action: string | null
}

export type BillingSummary = {
    plan: string | null
    label: string | null
    state: 'trialing' | 'active' | 'past_due' | 'ending' | 'read_only'
    read_only: boolean
    can_manage: boolean
    href: string
    banner: BillingBannerProps | null
    notes: BillingNotes
}

export type BillingPage = {
    can_manage: boolean
    plan: {
        key: string | null
        label: string
        badge: { label: string; tone: 'accent' | 'warning' | 'destructive' }
        line: string
        amount: string
        usage: string
        note: string | null
        primary: 'change' | 'choose' | 'keep' | null
        self_host: boolean
    }
    payment: { label: string; expires: string | null } | null
    payment_note: string
    invoices: {
        id: string
        period: string
        number: string
        amount: string
        status: { label: string; tone: 'success' | 'warning' | 'destructive' | 'outline' }
        pdf: string | null
    }[]
    invoice_count: number
    invoices_note: string | null
    receipts_to: string | null
}
