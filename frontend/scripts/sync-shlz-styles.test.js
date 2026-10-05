import { mkdtemp, mkdir, readFile, rm, writeFile } from 'node:fs/promises'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'
import { execFileSync, spawnSync } from 'node:child_process'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { buildStyles, revision, syncShlzStyles } from './sync-shlz-styles.mjs'

vi.mock('node:child_process', async importOriginal => ({ ...await importOriginal(), execFileSync: vi.fn() }))
let root
const tokens = {
  $schema: 'ignored',
  source: { 'Dark Blue': '#0B1623', spacing: 8 },
  semantic: { text: '{source.Dark Blue}', nested: '{semantic.text}' },
}
const readSource = path => path.endsWith('tokens.json') ? JSON.stringify(tokens) : `/* ${path} */\n.fixture { color: inherit; }\n`

beforeEach(async () => {
  root = await mkdtemp(join(tmpdir(), 'ic-shlz-test-'))
  execFileSync.mockImplementation((_binary, args) => readSource(args[2].slice(revision.length + 1)))
})
afterEach(async () => {
  vi.clearAllMocks()
  await rm(root, { recursive: true, force: true })
})

it('writes resolved tokens and verbatim components only from the pinned Git objects', async () => {
  await mkdir(join(root, 'packages/tokens'), { recursive: true })
  await writeFile(join(root, 'packages/tokens/tokens.json'), '{"uncommitted":"do not use"}')
  const output = pathToFileURL(`${root}/output/`)
  await syncShlzStyles(root, output)
  const css = await readFile(new URL('tokens.css', output), 'utf8')
  expect(css).toContain('--shlz-source-dark-blue: #0B1623;')
  expect(css).toContain('--shlz-source-spacing: 8;')
  expect(css).toContain('--shlz-semantic-nested: #0B1623;')
  expect(css).not.toMatch(/schema|uncommitted|do not use/)
  for (const name of ['status-badge', 'table', 'popover', 'choice', 'button', 'notification', 'file-row', 'file-upload']) {
    expect(await readFile(new URL(`${name}.css`, output), 'utf8')).toContain(readSource(`packages/styles/components/${name}.css`))
  }
  expect(await readFile(new URL('file-upload.ts', output), 'utf8')).toBe(readSource('packages/behaviors/src/file-upload.ts'))
  expect(await readFile(new URL('copy.svg', output), 'utf8')).toBe(readSource('packages/icons/normalized/interface/copy.svg'))
  for (const [binary, args, options] of execFileSync.mock.calls) {
    expect(binary).toBe('/usr/bin/git')
    expect(args.slice(0, 2)).toEqual(['--no-replace-objects', 'show'])
    expect(args[2]).toMatch(new RegExp(`^${revision}:packages/`))
    expect(options.cwd).toBeTruthy()
  }
})

it('fails on cycles and missing token aliases instead of emitting broken CSS', () => {
  expect(() => buildStyles(() => '{"a":"{b}","b":"{a}"}')).toThrow('Circular token alias')
  expect(() => buildStyles(() => '{"a":"{missing}"}')).toThrow('Unknown token alias')
})

it('rejects missing and non-directory repository arguments before running Git', async () => {
  await expect(syncShlzStyles()).rejects.toThrow('Source repository is required')
  const file = join(root, '--help')
  await writeFile(file, 'not a directory')
  await expect(syncShlzStyles(file)).rejects.toThrow('must be a directory')
  expect(execFileSync).not.toHaveBeenCalled()
})

it('rejects arbitrary CLI source paths before inspecting or reading them', () => {
  const script = fileURLToPath(new URL('./sync-shlz-styles.mjs', import.meta.url))
  const result = spawnSync(process.execPath, [script, '../../outside-source'], { encoding: 'utf8' })
  expect(result.status).toBe(1)
  expect(result.stderr).toContain('does not accept source paths')
})

it('does not create a partial output when the pinned source revision is missing', async () => {
  execFileSync.mockImplementation(() => { throw new Error('Git revision unavailable') })
  const output = pathToFileURL(`${root}/output/`)
  await expect(syncShlzStyles(root, output)).rejects.toThrow('Git revision unavailable')
  await expect(readFile(new URL('tokens.css', output))).rejects.toThrow()
})
