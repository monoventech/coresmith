// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

export type GitHubReachability = { reachable: boolean; host: string; sentence: string | null }

export type GitHubRegistration = {
    state: string
    key: string
    personal_action: string
    organization_action: string
    manifest: Record<string, unknown>
    webhook: { hook_attributes: { url: string; active: boolean }; default_events: string[] }
    name: string
    reachability: GitHubReachability
}

export type GitHubConnection = {
    id: string
    account: string
    account_type: string | null
    repository_selection: string | null
    repositories: number | null
    repositories_href: string
    permissions: string
    token_expires_at: string | null
    suspended: boolean
    manage_url: string | null
    app_name: string | null
    disconnect_href: string
}

export type GitHubRegisteredApp = {
    key: string
    name: string
    slug: string
    owner: string | null
    owner_type: string | null
    settings_url: string | null
    installed: boolean
    install_href: string
    link_href: string
    remove_href: string | null
}

export type GitHubConnect = { install_href: string; link_href: string }

export type GitHubSource = { id: string; account: string; repositories_href: string; manage_url: string | null }

export type GitHubRepository = { id: string; full_name: string; private: boolean; default_branch: string }

export type GitHubPick = { id: string; full_name: string; default_branch: string; branches: string[] }

export type GitHubInstallationChoice = {
    id: string
    account: string
    account_type: string
    repository_selection: string
    refusal: string | null
}

export type GitHubChoice = {
    app: string
    installations: GitHubInstallationChoice[]
    post_href: string
    cancel_href: string
}

export type GitHubWebhook = { app_name: string; instant: boolean; sentence: string; sync_href: string | null }

export type GitHubSettings = {
    can_register: boolean
    registration: GitHubRegistration | null
    elsewhere: string | null
    connections: GitHubConnection[]
    apps: GitHubRegisteredApp[]
    connect: GitHubConnect | null
    choose: GitHubChoice | null
    webhook: GitHubWebhook[]
    members: { id: string; account: string; member: string; remove_href: string }[]
    can: { manage: boolean }
}

export type GitHubSetup = {
    connected: boolean
    name: string | null
    label: string | null
    connect_href: string | null
    registration: GitHubRegistration | null
    elsewhere: string | null
    can_manage: boolean
    settings_href: string
}

export const REPOSITORY_NOT_VISIBLE =
    'GitHub does not give Coresmith access to that repository. Pick another, or add it under Manage repositories on GitHub.'

export const ORGANIZATION_PATTERN = /^[A-Za-z0-9](?:[A-Za-z0-9]|-(?=[A-Za-z0-9])){0,38}$/

export const APP_NAME_PATTERN = /^[A-Za-z0-9][A-Za-z0-9 -]{0,33}$/

export function manifestFor(registration: GitHubRegistration, name: string, withWebhook: boolean): string {
    return JSON.stringify({ ...registration.manifest, name, ...(withWebhook ? registration.webhook : {}) })
}

export function organizationAction(registration: GitHubRegistration, organization: string): string {
    return registration.organization_action.replace('{organization}', encodeURIComponent(organization))
}
