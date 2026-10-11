// Writes the split Slidev produced for every deck in the fixture, into the fixture.
//
// The deck Markdown lives in the fixture rather than in this repository's sources,
// so adding a case to the parity test is a case the recording covers: there is no
// second list to keep in step, and the decks cannot drift apart from what Slidev
// was asked.
import { readFileSync, writeFileSync } from 'node:fs'
import path from 'node:path'
import process from 'node:process'
import { fileURLToPath } from 'node:url'
import { parseSync } from './core.runnable.ts'

// Where this repository is, from this file: `tools/slidev-parity/` is two directories up.
const root = path.resolve(fileURLToPath(new URL('../..', import.meta.url)))

// The recording is written back to whatever the argument names, so the argument is held
// to where it may point rather than trusted. `regenerate.sh` passes the one file this is
// for; another path inside the repository is the caller's business, and one outside it is
// not something a recording script acts on.
const argument = process.argv[2] ?? ''
const relative = path.relative(root, path.resolve(argument))

if (relative.startsWith('..') || path.isAbsolute(relative)) {
  console.error(`Refusing to write outside the repository: ${argument}`)
  process.exit(2)
}

const fixture = readFileSync(argument, 'utf8')
const recorded = JSON.parse(fixture)

recorded.slides = Object.fromEntries(recorded.decks.map(({ name, markdown }) => {
  const parsed = parseSync(markdown, `${name}.md`)

  return [name, {
    slides: parsed.slides.map((slide) => ({
      index: slide.index,
      raw: slide.raw,
      content: slide.content,
      frontmatter: slide.frontmatter ?? {},
      title: slide.title ?? null,
      level: slide.level ?? null,
      note: slide.note ?? null,
    })),
  }]
}))

writeFileSync(argument, `${JSON.stringify(recorded, null, 1)}\n`)
console.log(`Recorded ${recorded.decks.length} decks.`)