import { createHash } from 'node:crypto'
import { mkdir, mkdtemp, readFile, rm, writeFile } from 'node:fs/promises'
import { join } from 'node:path'
import { tmpdir } from 'node:os'
import { afterEach, describe, expect, test } from 'vitest'
import {
  downloadResponse,
  loadCheckpoint,
  normalizeFileUrl,
  parseArguments,
  readSnapshotFiles,
  assertOutsideGit,
  writePrivateJsonLines,
} from './bitrix-files.mjs'

const temporaryDirectories = []

afterEach(async () => {
  await Promise.all(temporaryDirectories.splice(0).map((directory) => rm(directory, { recursive: true, force: true })))
})

describe('Bitrix file migration tooling', () => {
  test('parses explicit command options', () => {
    expect(parseArguments(['download', '--snapshot=/safe/input', '--limit=10'])).toEqual({
      command: 'download',
      options: { snapshot: '/safe/input', limit: '10' },
    })
  })

  test('normalizes unicode URL and enforces host and path', () => {
    const normalized = normalizeFileUrl(
      'https://portal.example/docs/file/FilesProposalTest/Отчёт 1.pdf#fragment',
      'portal.example',
    )

    expect(normalized).toBe('https://portal.example/docs/file/FilesProposalTest/%D0%9E%D1%82%D1%87%D1%91%D1%82%201.pdf%23fragment')
    expect(() => normalizeFileUrl(
      'https://attacker.example/docs/file/FilesProposalTest/file.pdf',
      'portal.example',
    )).toThrow('outside the snapshot allowlist')
    expect(() => normalizeFileUrl('https://portal.example/other/file.pdf', 'portal.example')).toThrow(
      'outside the legacy file directory',
    )
  })

  test('verifies snapshot and deduplicates associations by source ID', async () => {
    const directory = await fixtureDirectory()
    const detailUrl = 'https://portal.example/docs/file/FilesProposalTest/file.pdf'
    const elements = [
      element('10', [{ id: 7, name: 'file.pdf', detailURL: detailUrl }], []),
      element('11', [], [{ id: 7, name: 'file.pdf', detailURL: detailUrl }]),
    ]
    await writeSnapshot(directory, elements)

    const result = await readSnapshotFiles(directory)

    expect(result.associations).toHaveLength(2)
    expect(result.uniqueFiles).toHaveLength(1)
    expect(result.allowedHost).toBe('portal.example')
  })

  test('rejects unsafe file identifiers and malformed detail JSON', async () => {
    const unsafe = await fixtureDirectory()
    await writeSnapshot(unsafe, [element('10', [{ id: '../escape', name: 'x', detailURL: 'https://portal.example/docs/file/FilesProposalTest/x' }], [])])
    await expect(readSnapshotFiles(unsafe)).rejects.toThrow('Unsafe source file identifier')

    const malformed = await fixtureDirectory()
    await writeSnapshot(malformed, [{ ID: '11', DETAIL_TEXT: '{invalid' }])
    await expect(readSnapshotFiles(malformed)).rejects.toThrow('Element 11 has malformed DETAIL_TEXT JSON')
  })

  test('rejects the workspace itself when it is a Git root', async () => {
    const directory = await fixtureDirectory()
    await mkdir(join(directory, '.git'))
    expect(() => assertOutsideGit(directory)).toThrow('outside a Git working tree')
  })

  test('reuses only matching associations and cleans stale partial file', async () => {
    const directory = await fixtureDirectory()
    const path = join(directory, 'associations.jsonl')
    const records = [{ sourceFileId: '7' }]
    await writeFile(`${path}.partial`, 'stale')
    await writePrivateJsonLines(path, records)
    await writePrivateJsonLines(path, records)
    await expect(writePrivateJsonLines(path, [{ sourceFileId: '8' }])).rejects.toThrow('does not match')
    expect(await readFile(path, 'utf8')).toBe('{"sourceFileId":"7"}\n')
  })

  test('streams response to a private object and calculates metadata', async () => {
    const directory = await fixtureDirectory()
    const destination = join(directory, '7')
    const body = new TextEncoder().encode('synthetic file')
    const response = new Response(body, {
      status: 200,
      headers: { 'content-type': 'application/pdf', 'content-length': String(body.length) },
    })

    const metadata = await downloadResponse({ response, destination, maxBytes: 1024 })

    expect(await readFile(destination, 'utf8')).toBe('synthetic file')
    expect(metadata).toEqual({
      bytes: body.length,
      sha256: createHash('sha256').update(body).digest('hex'),
      mime: 'application/pdf',
    })
  })

  test('rejects OAuth redirects and oversized bodies without publishing object', async () => {
    const directory = await fixtureDirectory()
    await expect(downloadResponse({
      response: new Response(null, { status: 302, headers: { location: 'https://oauth.example/' } }),
      destination: join(directory, 'redirect'),
      maxBytes: 1024,
    })).rejects.toThrow('AUTH_REQUIRED')
    await expect(downloadResponse({
      response: new Response('too large', { status: 200, headers: { 'content-length': '2048' } }),
      destination: join(directory, 'large'),
      maxBytes: 1024,
    })).rejects.toThrow('FILE_TOO_LARGE')
    await expect(downloadResponse({
      response: new Response('<form>login</form>', { status: 200, headers: { 'content-type': 'text/html' } }),
      destination: join(directory, 'login'),
      maxBytes: 1024,
    })).rejects.toThrow('AUTH_REQUIRED')
  })

  test('uses the latest checkpoint record when resuming', async () => {
    const directory = await fixtureDirectory()
    const checkpoint = join(directory, 'checkpoint.jsonl')
    await writeFile(checkpoint, [
      JSON.stringify({ sourceFileId: '7', status: 'failed' }),
      JSON.stringify({ sourceFileId: '7', status: 'downloaded', bytes: 10 }),
    ].join('\n') + '\n')

    expect((await loadCheckpoint(checkpoint)).get('7')).toEqual({
      sourceFileId: '7',
      status: 'downloaded',
      bytes: 10,
    })
  })
})

async function fixtureDirectory() {
  const directory = await mkdtemp(join(tmpdir(), 'ic-bitrix-files-'))
  temporaryDirectories.push(directory)
  return directory
}

function element(id, supporting, reports) {
  return {
    ID: id,
    DETAIL_TEXT: JSON.stringify({ supportingDocFiles: supporting, reportFiles: reports }),
  }
}

async function writeSnapshot(directory, elements) {
  const elementsContent = elements.map((value) => JSON.stringify(value)).join('\n') + '\n'
  await writeFile(join(directory, 'elements.jsonl'), elementsContent)
  await writeFile(join(directory, 'manifest.json'), JSON.stringify({
    formatVersion: 1,
    complete: true,
    files: {
      'elements.jsonl': {
        bytes: Buffer.byteLength(elementsContent),
        sha256: createHash('sha256').update(elementsContent).digest('hex'),
      },
    },
  }))
}
