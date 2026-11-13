// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

import { createHash } from 'node:crypto'
import { readdirSync, readFileSync, statSync } from 'node:fs'
import { join } from 'node:path'

export const WATCHED = [
    'app/Services/Runtime',
    'app/Services/Detection',
    'app/Services/Deployment',
    'app/Services/Process',
    'app/Jobs/DeployApplication.php',
    'tests/Corpus/fixed.json'
]

function files(path: string): string[] {
    return statSync(path).isDirectory()
        ? readdirSync(path)
              .sort()
              .flatMap((name) => files(join(path, name)))
        : [path]
}

export function codeHash(root = '.'): string {
    const hash = createHash('sha256')

    for (const file of WATCHED.flatMap((path) => files(join(root, path)))) {
        hash.update(file.slice(root.length + 1) + '\0')
        hash.update(readFileSync(file))
        hash.update('\0')
    }

    return hash.digest('hex').slice(0, 16)
}

if (import.meta.main) {
    console.log(codeHash())
}
