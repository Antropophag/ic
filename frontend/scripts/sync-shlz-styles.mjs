import { execFileSync } from 'node:child_process'
import { realpathSync, statSync } from 'node:fs'
import { mkdir, writeFile } from 'node:fs/promises'
import { pathToFileURL } from 'node:url'

// Read immutable Git objects, never execute code from the supplied repository.
export const revision = '1864b3c0cb327485f08c48b14c88730ec852b03c'
const components = ['status-badge', 'table', 'popover', 'choice', 'button']
const banner = `/* Generated from shlz-ui ${revision}. Run frontend/scripts/sync-shlz-styles.mjs to update. */\n`

function flattenTokens(value, prefix = [], output = new Map()) {
  for (const [key, item] of Object.entries(value)) {
    if (key.startsWith('$')) continue
    const path = [...prefix, key]
    if (item && typeof item === 'object') flattenTokens(item, path, output)
    else output.set(path.join('.'), item)
  }
  return output
}

/** Compile token aliases and verbatim component styles from a source reader. */
export function buildStyles(readSource) {
  const flat = flattenTokens(JSON.parse(readSource('packages/tokens/tokens.json')))
  function resolve(key, stack = []) {
    if (stack.includes(key)) throw new Error(`Circular token alias: ${key}`)
    if (!flat.has(key)) throw new Error(`Unknown token alias: ${key}`)
    const value = flat.get(key)
    const alias = typeof value === 'string' && value.match(/^\{(.+)\}$/)
    return alias ? resolve(alias[1], [...stack, key]) : value
  }
  const declarations = [...flat.keys()].map(key => {
    const name = key.replaceAll(/[^a-z0-9-]/gi, '-').replaceAll(/-+/g, '-').replaceAll(/^-|-$/g, '').toLowerCase()
    return `  --shlz-${name}: ${resolve(key)};`
  })
  return {
    'tokens.css': `${banner}:root {\n${declarations.join('\n')}\n}\n`,
    ...Object.fromEntries(components.map(name => [
      `${name}.css`, banner + readSource(`packages/styles/components/${name}.css`),
    ])),
  }
}

/** Regenerate the local distribution from the pinned revision of a local Git repository. */
export async function syncShlzStyles(repository, destination = new URL('../src/vendor/shlz/', import.meta.url)) {
  if (!repository) throw new Error('Usage: node scripts/sync-shlz-styles.mjs /path/to/shlz-ui')
  const cwd = realpathSync(repository)
  if (!statSync(cwd).isDirectory()) throw new TypeError('SHLZ source must be a directory')
  const readSource = path => execFileSync('/usr/bin/git', ['--no-replace-objects', 'show', `${revision}:${path}`], { cwd, encoding: 'utf8' })
  const files = buildStyles(readSource)
  await mkdir(destination, { recursive: true })
  await Promise.all(Object.entries(files).map(([name, content]) => writeFile(new URL(name, destination), content)))
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  await syncShlzStyles(process.argv[2])
}
