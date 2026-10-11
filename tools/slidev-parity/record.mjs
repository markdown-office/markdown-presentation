// Writes the split Slidev produced for every deck in the fixture, into the fixture.
//
// The deck Markdown lives in the fixture rather than in this repository's sources,
// so adding a case to the parity test is a case the recording covers: there is no
// second list to keep in step, and the decks cannot drift apart from what Slidev
// was asked.
import { readFileSync, writeFileSync } from 'node:fs'
import process from 'node:process'
import { parseSync } from './core.runnable.ts'

const fixture = readFileSync(process.argv[2], 'utf8')
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

writeFileSync(process.argv[2], `${JSON.stringify(recorded, null, 1)}\n`)
console.log(`Recorded ${recorded.decks.length} decks.`)
