import { mkdtemp, mkdir, rm, writeFile } from 'node:fs/promises'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { lintMarkdown } from './lint-markdown.mjs'
import { micromark } from 'micromark'
import { math, mathHtml } from 'micromark-extension-math'

let root
beforeEach(async () => {
  root = await mkdtemp(join(tmpdir(), 'ic-markdown-test-'))
  vi.spyOn(console, 'log').mockImplementation(() => {})
  vi.spyOn(console, 'error').mockImplementation(() => {})
})
afterEach(async () => {
  vi.restoreAllMocks()
  await rm(root, { recursive: true, force: true })
})

it('keeps the repository globs and configured HTML, title and line-length rules', async () => {
  await Promise.all(['docs', '.github', 'frontend'].map(name => mkdir(join(root, name))))
  await writeFile(join(root, 'README.md'), `<span>${'Long text '.repeat(20)}</span>\n`)
  await writeFile(join(root, 'docs/guide.md'), '# Guide\n\nText.\n')
  await writeFile(join(root, '.github/pull_request_template.md'), '# Review\n\nText.\n')
  await writeFile(join(root, 'frontend/ignored.md'), '# Duplicate\n\n# Duplicate\n')
  expect(await lintMarkdown(root)).toBe(0)
  expect(console.log).toHaveBeenCalledWith('Markdown: 3 files, 0 errors')
  expect(console.error).not.toHaveBeenCalled()
})

it('reports the file, line and rule for invalid Markdown', async () => {
  await writeFile(join(root, 'README.md'), '# Duplicate\n\n# Duplicate\n')
  expect(await lintMarkdown(root)).toBeGreaterThan(0)
  expect(console.error).toHaveBeenCalledWith(expect.stringContaining('README.md:3 MD024'))
})

it('does not silently pass when no configured files are found', async () => {
  await expect(lintMarkdown(root)).rejects.toThrow('found no files')
})


it('keeps inline and block math compatible with the patched KaTeX dependency', async () => {
  const markdown = '# Math\n\nInline $x^2$ and block:\n\n$$\nx^2 + y^2 = z^2\n$$\n'
  await writeFile(join(root, 'README.md'), markdown)
  const html = micromark(markdown, { extensions: [math()], htmlExtensions: [mathHtml()] })
  expect(html).toContain('class="katex"')
  expect(html).toContain('class="katex-display"')
  expect(html).not.toContain('katex-error')
  expect(await lintMarkdown(root)).toBe(0)
  expect(console.error).not.toHaveBeenCalled()
})
