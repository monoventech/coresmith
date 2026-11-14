// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

export type PackageSourceValue = 'composer' | 'npm' | 'python' | 'maven' | 'ruby' | 'git'

export type PackageEntry = {
    id: string
    source: PackageSourceValue
    source_label: string
    target: string
    scope: string | null
    username: string | null
    hint: string | null
    project_id: string | null
    project: string | null
    added: string | null
    added_by: string | null
}

export type PackageSettings = {
    can_manage: boolean
    sources: { value: PackageSourceValue; label: string }[]
    projects: { id: string; name: string }[]
    entries: PackageEntry[]
}

export type PackageFields = {
    target: string
    placeholder: string
    targetHint: string | null
    username: 'required' | 'optional' | null
    usernameHint: string | null
    secret: string
    scopes: boolean
}

export const packageFields: Record<PackageSourceValue, PackageFields> = {
    composer: {
        target: 'Host',
        placeholder: 'nova.laravel.com',
        targetHint: 'For Laravel Nova and Spark, the username is your account email and the password a license key.',
        username: 'optional',
        usernameHint: 'Leave it empty to send the token as a bearer token, which private Packagist accepts.',
        secret: 'Password or license key',
        scopes: false
    },
    npm: {
        target: 'Registry',
        placeholder: 'https://npm.pkg.github.com',
        targetHint: 'npm, pnpm, Yarn, Bun and Deno all install from it with this token.',
        username: null,
        usernameHint: null,
        secret: 'Token',
        scopes: true
    },
    python: {
        target: 'Index host',
        placeholder: 'pypi.acme.dev',
        targetHint: 'pip, uv and Poetry use it for the index your project already names.',
        username: 'required',
        usernameHint: null,
        secret: 'Password or token',
        scopes: false
    },
    maven: {
        target: 'Repository ID',
        placeholder: 'acme-releases',
        targetHint: "The repository's <id> in pom.xml, or its name in Gradle.",
        username: 'required',
        usernameHint: null,
        secret: 'Password or token',
        scopes: false
    },
    ruby: {
        target: 'Gem source host',
        placeholder: 'gems.acme.dev',
        targetHint: null,
        username: 'optional',
        usernameHint: 'Leave it empty when the token goes where a username would, as Gemfury expects.',
        secret: 'Password or token',
        scopes: false
    },
    git: {
        target: 'Host and owner',
        placeholder: 'github.com/acme',
        targetHint:
            'Go modules under this path install privately. Composer, npm, pip and Bundler dependencies on this host use the token too.',
        username: 'optional',
        usernameHint: 'Leave it empty for GitHub, GitLab and Bitbucket tokens.',
        secret: 'Token',
        scopes: false
    }
}
