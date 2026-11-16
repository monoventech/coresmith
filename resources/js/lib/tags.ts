// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

export const TAG_LIMIT = 5

export const TAG_LENGTH = 24

const SHAPE = /^[\p{L}\p{N}][\p{L}\p{N} ._-]*$/u

export const TAG_MESSAGES = {
    many: `An app can have up to ${TAG_LIMIT} tags.`,
    long: `Keep each tag to ${TAG_LENGTH} characters or fewer.`,
    shape: 'Start each tag with a letter or number, then use letters, numbers, spaces, dots, dashes or underscores.'
}

export function cleanTag(value: string): string {
    return value.replace(/\s+/gu, ' ').trim()
}

export function sameTag(a: string, b: string): boolean {
    return a.toLocaleLowerCase() === b.toLocaleLowerCase()
}

export function tagProblem(tag: string, count: number): string | null {
    if (count >= TAG_LIMIT) {
        return TAG_MESSAGES.many
    }

    if ([...tag].length > TAG_LENGTH) {
        return TAG_MESSAGES.long
    }

    return SHAPE.test(tag) ? null : TAG_MESSAGES.shape
}

export function firstTagError(errors: Record<string, string>): string | null {
    const key = Object.keys(errors).find((each) => each === 'tags' || each.startsWith('tags.'))

    return key === undefined ? null : errors[key]
}
