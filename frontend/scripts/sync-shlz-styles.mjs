import { execFileSync } from 'node:child_process'
import { mkdir, writeFile } from 'node:fs/promises'

// Pin the design-system source; never consume an adjacent worktree's uncommitted files.
const revision = '1864b3c0cb327485f08c48b14c88730ec852b03c'
const repository = process.argv[2]
if (!repository) throw new Error('Usage: node scripts/sync-shlz-styles.mjs /path/to/shlz-ui')
const read = path => execFileSync('git', ['-C', repository, 'show', `${revision}:${path}`], { encoding: 'utf8' })
const helper = Buffer.from(read('tools/lib.mjs')).toString('base64')
const { flatten, resolveAliases, kebab } = await import(`data:text/javascript;base64,${helper}`)
const tokens = resolveAliases(flatten(JSON.parse(read('packages/tokens/tokens.json'))))
const destination = new URL('../src/vendor/shlz/', import.meta.url)
await mkdir(destination, { recursive: true })
const banner = `/* Generated from shlz-ui ${revision}. Run frontend/scripts/sync-shlz-styles.mjs to update. */\n`
await writeFile(new URL('tokens.css', destination), `${banner}:root {\n${Object.entries(tokens).map(([key, value]) => `  --shlz-${kebab(key.split('.'))}: ${value};`).join('\n')}\n}\n`)
await writeFile(new URL('status-badge.css', destination), banner + read('packages/styles/components/status-badge.css'))
await writeFile(new URL('table.css', destination), banner + read('packages/styles/components/table.css'))
for (const component of ['popover', 'choice', 'button']) {
  await writeFile(new URL(`${component}.css`, destination), banner + read(`packages/styles/components/${component}.css`))
}
