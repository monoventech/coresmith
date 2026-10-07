import fs from 'node:fs'
import { createRequire } from 'node:module'
import path from 'node:path'
import { pathToFileURL } from 'node:url'

const root = path.resolve(path.dirname(new URL(import.meta.url).pathname), '..')
const require = createRequire(path.join(root, 'package.json'))
const { __unstable__loadDesignSystem } = await import(pathToFileURL(path.join(root, 'node_modules/@tailwindcss/node/dist/index.mjs')).href)
const { Scanner } = require('@tailwindcss/oxide')

const write = process.argv.includes('--write')
const entry = path.join(root, 'resources/css/app.css')
const design = await __unstable__loadDesignSystem(fs.readFileSync(entry, 'utf8'), { base: path.dirname(entry) })
const scanner = new Scanner({})
const canonical = new Map()

function sources(directory, found = []) {
    for (const item of fs.readdirSync(directory, { withFileTypes: true })) {
        const full = path.join(directory, item.name)

        if (item.isDirectory()) {
            if (item.name !== 'node_modules') {
                sources(full, found)
            }
        } else if (/\.(svelte|ts|js)$/.test(item.name) || item.name.endsWith('.blade.php')) {
            found.push(full)
        }
    }

    return found
}

function canonicalFor(candidate) {
    if (!canonical.has(candidate)) {
        const [result] = design.canonicalizeCandidates([candidate], { rem: 16 })

        canonical.set(candidate, result ?? candidate)
    }

    return canonical.get(candidate)
}

const problems = []

for (const file of [...sources(path.join(root, 'resources/js')), ...sources(path.join(root, 'resources/views'))]) {
    const content = fs.readFileSync(file, 'utf8')
    const extension = file.endsWith('.blade.php') ? 'php' : path.extname(file).slice(1)
    const edits = scanner
        .getCandidatesWithPositions({ content, extension })
        .filter(({ candidate, position }) => content.slice(position, position + candidate.length) === candidate)
        .map(({ candidate, position }) => ({ candidate, position, replacement: canonicalFor(candidate) }))
        .filter(({ candidate, replacement }) => replacement !== candidate)

    if (edits.length === 0) {
        continue
    }

    const relative = path.relative(root, file)

    for (const edit of edits) {
        const line = content.slice(0, edit.position).split('\n').length

        problems.push(`${relative}:${line}  ${edit.candidate} can be written as ${edit.replacement}`)
    }

    if (write) {
        let next = content

        for (const edit of [...edits].sort((a, b) => b.position - a.position)) {
            next = next.slice(0, edit.position) + edit.replacement + next.slice(edit.position + edit.candidate.length)
        }

        fs.writeFileSync(file, next)
    }
}

if (problems.length === 0) {
    console.log('Every Tailwind class is already in its canonical form.')
    process.exit(0)
}

console.log(problems.join('\n'))

if (write) {
    console.log(`\nRewrote ${problems.length} classes. Run bun run format to re-sort them.`)
    process.exit(0)
}

console.log(`\n${problems.length} classes are not in their canonical form. Run bun run lint:tailwind:fix to rewrite them.`)
process.exit(1)
