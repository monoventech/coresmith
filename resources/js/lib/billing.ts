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
