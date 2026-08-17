import { createHash } from 'node:crypto'
import { link, mkdir, mkdtemp, readFile, rm, symlink, writeFile } from 'node:fs/promises'
import { join } from 'node:path'
import { tmpdir } from 'node:os'
import { afterEach, describe, expect, test, vi } from 'vitest'
import {
  downloadResponse,
  collectReportMetadata,
  findLegacyDownloadLink,
  loadCheckpoint,
  normalizeFileUrl,
  parseLegacyFileModifiedAt,
  readLegacyFileModifiedAt,
  plusSubstitutionCandidates,
  parseArguments,
  readSnapshotFiles,
  assertOutsideGit,
  writePrivateJsonLines,
  writeWorkspaceSource,
  verifyWorkspace,
} from './bitrix-files.mjs'

const temporaryDirectories = []

afterEach(async () => {
  vi.restoreAllMocks()
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

  test('removes legacy padding immediately before a file extension', () => {
    expect(normalizeFileUrl(
      'https://portal.example/docs/file/FilesProposalTest/4010101006Б    .tif',
      'portal.example',
    )).toBe('https://portal.example/docs/file/FilesProposalTest/4010101006%D0%91.tif')
  })

  test('restores three plus signs represented by legacy padding', () => {
    expect(normalizeFileUrl(
      'https://portal.example/docs/file/FilesProposalTest/0060700001Е    (1).tif',
      'portal.example',
    )).toBe('https://portal.example/docs/file/FilesProposalTest/0060700001%D0%95%2B%2B%2B%20(1).tif')
  })

  test('preserves unrelated runs of four spaces', () => {
    expect(normalizeFileUrl(
      'https://portal.example/docs/file/FilesProposalTest/report    final.pdf',
      'portal.example',
    )).toBe('https://portal.example/docs/file/FilesProposalTest/report%20%20%20%20final.pdf')
  })

  test('builds single-plus fallback candidates for legacy spaces', () => {
    expect(plusSubstitutionCandidates(
      'https://portal.example/docs/file/FilesProposalTest/one%20two%20three.pdf',
    )).toEqual([
      'https://portal.example/docs/file/FilesProposalTest/one%20two%20three.pdf',
      'https://portal.example/docs/file/FilesProposalTest/one%2Btwo%20three.pdf',
      'https://portal.example/docs/file/FilesProposalTest/one%20two%2Bthree.pdf',
    ])
  })

  test('finds a legacy download link after a missing original URL', async () => {
    const downloadLink = { waitFor: vi.fn().mockResolvedValue(undefined) }
    const page = {
      goto: vi.fn()
        .mockResolvedValueOnce({ status: () => 404 })
        .mockResolvedValueOnce({ status: () => 200 }),
      url: vi.fn().mockReturnValue('https://portal.example/docs/file/FilesProposalTest/one%2Btwo.pdf'),
      locator: vi.fn().mockReturnValue(downloadLink),
    }

    await expect(findLegacyDownloadLink(
      page,
      'https://portal.example/docs/file/FilesProposalTest/one%20two.pdf',
      1_000,
    )).resolves.toBe(downloadLink)
    expect(page.goto).toHaveBeenNthCalledWith(2,
      'https://portal.example/docs/file/FilesProposalTest/one%2Btwo.pdf',
      { waitUntil: 'domcontentloaded', timeout: 1_000 },
    )
    expect(downloadLink.waitFor).toHaveBeenCalledWith({ state: 'attached', timeout: 1_000 })
  })

  test('parses the Moscow timestamp displayed by a legacy file card', () => {
    expect(parseLegacyFileModifiedAt('Размер: 12 МБ\nИзменен:</td><td>02.09.2021 14:52:20</td>')).toBe(
      '2021-09-02T14:52:20+03:00',
    )
    expect(() => parseLegacyFileModifiedAt('Дата отсутствует')).toThrow('FILE_MODIFIED_AT_NOT_FOUND')
    expect(() => parseLegacyFileModifiedAt('Изменён: 31.02.2021 14:52:20')).toThrow('FILE_MODIFIED_AT_INVALID')
  })

  test('reads file metadata through the authenticated request context and retries a legacy URL', async () => {
    const missing = { status: () => 404, url: () => 'https://portal.example/missing', text: vi.fn() }
    const found = {
      status: () => 200,
      url: () => 'https://portal.example/docs/file/FilesProposalTest/one%2Btwo.pdf',
      text: vi.fn().mockResolvedValue('Изменен:</td><td>02.09.2021 14:52:20</td>'),
    }
    const request = { get: vi.fn().mockResolvedValueOnce(missing).mockResolvedValueOnce(found) }

    await expect(readLegacyFileModifiedAt(
      request,
      'https://portal.example/docs/file/FilesProposalTest/one%20two.pdf',
      1_000,
    )).resolves.toBe('2021-09-02T14:52:20+03:00')
    expect(request.get).toHaveBeenCalledTimes(2)
  })

  test('collects only deduplicated reports with bounded concurrency and resumes without a browser', async () => {
    const snapshot = await fixtureDirectory()
    const workspace = await fixtureDirectory()
    await mkdir(join(workspace, 'browser-profile'))
    const detailUrl = id => `https://portal.example/docs/file/FilesProposalTest/${id}.pdf`
    await writeSnapshot(snapshot, [
      element('10', [{ id: 'support', name: 'support.pdf', detailURL: detailUrl('support') }], [
        { id: 'report-1', name: 'one.pdf', detailURL: detailUrl('one') },
        { id: 'report-2', name: 'two.pdf', detailURL: detailUrl('two') },
      ]),
      element('11', [], [
        { id: 'report-1', name: 'one.pdf', detailURL: detailUrl('one') },
        { id: 'report-3', name: 'three.pdf', detailURL: detailUrl('three') },
      ]),
    ])
    let active = 0
    let maximumActive = 0
    const request = { get: vi.fn().mockImplementation(async url => {
      active++
      maximumActive = Math.max(maximumActive, active)
      await new Promise(resolve => setTimeout(resolve, 0))
      active--
      return { status: () => 200, url: () => url, text: async () => 'Изменен: 02.09.2021 14:52:20' }
    }) }
    const context = { request, close: vi.fn().mockResolvedValue(undefined) }
    const launch = vi.fn().mockResolvedValue(context)
    vi.spyOn(process.stdout, 'write').mockReturnValue(true)

    await collectReportMetadata(snapshot, workspace, { concurrency: '2' }, launch)

    expect(request.get).toHaveBeenCalledTimes(3)
    expect(maximumActive).toBe(2)
    const records = (await readFile(join(workspace, 'report-metadata.jsonl'), 'utf8')).trim().split('\n').map(JSON.parse)
    expect(records.map(record => record.sourceFileId).sort()).toEqual(['report-1', 'report-2', 'report-3'])
    expect(records.every(record => record.status === 'collected')).toBe(true)

    await collectReportMetadata(snapshot, workspace, { concurrency: '2' }, vi.fn(() => { throw new Error('must not launch') }))
    expect((await readFile(join(workspace, 'report-metadata.jsonl'), 'utf8')).trim().split('\n')).toHaveLength(3)
  })

  test('handles a snapshot without reports without requiring a browser profile', async () => {
    const snapshot = await fixtureDirectory()
    const workspace = await fixtureDirectory()
    await writeSnapshot(snapshot, [element('10', [{
      id: 'support', name: 'support.pdf', detailURL: 'https://portal.example/docs/file/FilesProposalTest/support.pdf',
    }], [])])
    const launch = vi.fn()
    vi.spyOn(process.stdout, 'write').mockReturnValue(true)

    await collectReportMetadata(snapshot, workspace, {}, launch)

    expect(launch).not.toHaveBeenCalled()
    expect(await readFile(join(workspace, 'report-metadata-source.jsonl'), 'utf8')).toContain('"reportFiles":0')
  })

  test('persists an individual failure and aborts explicitly on OAuth', async () => {
    const snapshot = await fixtureDirectory()
    const workspace = await fixtureDirectory()
    await mkdir(join(workspace, 'browser-profile'))
    await writeSnapshot(snapshot, [element('10', [], [
      { id: 'failed', name: 'failed.pdf', detailURL: 'https://portal.example/docs/file/FilesProposalTest/failed.pdf' },
      { id: 'oauth', name: 'oauth.pdf', detailURL: 'https://portal.example/docs/file/FilesProposalTest/oauth.pdf' },
    ])])
    const request = { get: vi.fn()
      .mockResolvedValueOnce({ status: () => 500, url: () => 'https://portal.example/docs/file/FilesProposalTest/failed.pdf' })
      .mockResolvedValueOnce({ status: () => 302, url: () => 'https://portal.example/oauth/authorize/' }) }
    const context = { request, close: vi.fn().mockResolvedValue(undefined) }

    await expect(collectReportMetadata(snapshot, workspace, { concurrency: '2' }, async () => context))
      .rejects.toThrow('AUTH_REQUIRED')
    const records = (await readFile(join(workspace, 'report-metadata.jsonl'), 'utf8')).trim().split('\n').map(JSON.parse)
    expect(records).toEqual([expect.objectContaining({ sourceFileId: 'failed', status: 'failed', error: 'FILE_PAGE_HTTP_500' })])
    expect(context.close).toHaveBeenCalledOnce()
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

  test('includes files attached to legacy comments', async () => {
    const directory = await fixtureDirectory()
    const details = {
      supportingDocFiles: [], reportFiles: [],
      commentsInitiator: [{ id: '51', files: [{ id: 9, name: 'comment.pdf', detailURL: 'https://portal.example/docs/file/FilesProposalTest/comment.pdf' }] }],
    }
    await writeSnapshot(directory, [{ ID: '10', DETAIL_TEXT: JSON.stringify(details) }])

    const result = await readSnapshotFiles(directory)

    expect(result.associations[0]).toMatchObject({
      requestNumber: '10', documentType: 'comment', commentType: 'initiator', sourceCommentId: '51', sourceFileId: '9',
    })
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

  test('concurrent conflicting writers cannot replace published associations', async () => {
    const directory = await fixtureDirectory()
    const path = join(directory, 'associations.jsonl')
    const first = [{ sourceFileId: '7' }]
    const second = [{ sourceFileId: '8' }]

    const results = await Promise.allSettled([
      writePrivateJsonLines(path, first),
      writePrivateJsonLines(path, second),
    ])
    const published = await readFile(path, 'utf8')

    expect(results.map(({ status }) => status).sort()).toEqual(['fulfilled', 'rejected'])
    expect(['{"sourceFileId":"7"}\n', '{"sourceFileId":"8"}\n']).toContain(published)
    await expect(writePrivateJsonLines(path, published.includes('"7"') ? second : first)).rejects.toThrow('does not match')
    expect(await readFile(path, 'utf8')).toBe(published)
  })

  test('removes safely identifiable partials left after publication', async () => {
    const directory = await fixtureDirectory()
    const path = join(directory, 'associations.jsonl')
    const records = [{ sourceFileId: '7' }]
    await writePrivateJsonLines(path, records)
    const linkedPartial = `${path}.123.crash.partial`
    const legacyPartial = `${path}.partial`
    await link(path, linkedPartial)
    await writeFile(legacyPartial, 'stale legacy partial', { mode: 0o600 })

    await writePrivateJsonLines(path, records)

    await expect(readFile(linkedPartial, 'utf8')).rejects.toThrow()
    await expect(readFile(legacyPartial, 'utf8')).rejects.toThrow()
    expect(await readFile(path, 'utf8')).toBe('{"sourceFileId":"7"}\n')
  })

  test('matching published associations remain resumable when partial cleanup fails', async () => {
    const directory = await fixtureDirectory()
    const path = join(directory, 'associations.jsonl')
    const records = [{ sourceFileId: '7' }]
    await writePrivateJsonLines(path, records)
    await mkdir(`${path}.partial`)
    const warning = vi.spyOn(process.stderr, 'write').mockReturnValue(true)

    await expect(writePrivateJsonLines(path, records)).resolves.toBeUndefined()
    expect(warning).toHaveBeenCalledWith(expect.stringContaining('Не удалось очистить partial-файлы'))
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

  test('verifies snapshot associations, objects, and checkpoint hashes', async () => {
    const { snapshot, workspace } = await validMigrationWorkspace()

    await expect(verifyWorkspace(snapshot, workspace)).resolves.toEqual({
      uniqueFiles: 1,
      associations: 1,
      verified: 1,
    })

    await writeFile(join(workspace, 'objects', '7'), 'corrupted')
    await expect(verifyWorkspace(snapshot, workspace)).rejects.toThrow('integrity check failed')
  })

  test('writes workspace identity from verified snapshot metadata', async () => {
    const snapshot = await fixtureDirectory()
    const workspace = await fixtureDirectory()
    const detailUrl = 'https://portal.example/docs/file/FilesProposalTest/file.pdf'
    await writeSnapshot(snapshot, [element('10', [{ id: 7, name: 'file.pdf', detailURL: detailUrl }], [])])
    const source = await readSnapshotFiles(snapshot)

    await writeWorkspaceSource(workspace, source)

    expect(JSON.parse(await readFile(join(workspace, 'source.json'), 'utf8'))).toEqual({
      listId: 114,
      snapshotFingerprint: source.manifest.files['elements.jsonl'].sha256,
    })
  })

  test('rejects workspace identity from another snapshot before payload checks', async () => {
    const { snapshot, workspace } = await validMigrationWorkspace()
    await writeFile(join(workspace, 'source.json'), `${JSON.stringify({
      listId: 115,
      snapshotFingerprint: '0'.repeat(64),
    })}\n`)

    await expect(verifyWorkspace(snapshot, workspace)).rejects.toThrow('Workspace source does not match the snapshot')
  })

  test('rejects associations that do not match the snapshot', async () => {
    const { snapshot, workspace } = await validMigrationWorkspace()
    await writeFile(join(workspace, 'associations.jsonl'), '{"sourceFileId":"other"}\n')
    await expect(verifyWorkspace(snapshot, workspace)).rejects.toThrow('associations do not match')
  })

  test.each([
    ['missing', async (workspace) => rm(join(workspace, 'objects', '7'))],
    ['extra', async (workspace) => writeFile(join(workspace, 'objects', '8'), 'extra')],
  ])('rejects a %s workspace object', async (_case, mutate) => {
    const { snapshot, workspace } = await validMigrationWorkspace()
    await mutate(workspace)
    await expect(verifyWorkspace(snapshot, workspace)).rejects.toThrow('objects do not match')
  })

  test.each([
    ['missing', ''],
    ['extra', `${checkpointRecord()}${JSON.stringify({ sourceFileId: '8', status: 'downloaded' })}\n`],
  ])('rejects a %s checkpoint entry', async (_case, checkpoint) => {
    const { snapshot, workspace } = await validMigrationWorkspace()
    await writeFile(join(workspace, 'checkpoint.jsonl'), checkpoint)
    await expect(verifyWorkspace(snapshot, workspace)).rejects.toThrow('checkpoint does not match')
  })

  test('rejects a checkpoint without downloaded status', async () => {
    const { snapshot, workspace } = await validMigrationWorkspace()
    await writeFile(join(workspace, 'checkpoint.jsonl'), checkpointRecord('failed'))
    await expect(verifyWorkspace(snapshot, workspace)).rejects.toThrow('no successful checkpoint')
  })

  test('rejects symbolic links in the object workspace', async () => {
    const { snapshot, workspace } = await validMigrationWorkspace()
    const target = join(workspace, 'target')
    await writeFile(target, 'historical document')
    await rm(join(workspace, 'objects', '7'))
    await symlink(target, join(workspace, 'objects', '7'))
    await expect(verifyWorkspace(snapshot, workspace)).rejects.toThrow('not a regular file')
  })
})

async function fixtureDirectory() {
  const directory = await mkdtemp(join(tmpdir(), 'ic-bitrix-files-'))
  temporaryDirectories.push(directory)
  return directory
}

async function validMigrationWorkspace() {
  const snapshot = await fixtureDirectory()
  const workspace = await fixtureDirectory()
  const detailUrl = 'https://portal.example/docs/file/FilesProposalTest/file.pdf'
  await writeSnapshot(snapshot, [element('10', [{ id: 7, name: 'file.pdf', detailURL: detailUrl }], [])])
  await mkdir(join(workspace, 'objects'))
  await writeFile(join(workspace, 'objects', '7'), 'historical document')
  await writeFile(join(workspace, 'checkpoint.jsonl'), checkpointRecord())
  await writePrivateJsonLines(join(workspace, 'associations.jsonl'), [{
    requestNumber: '10',
    documentType: 'supporting',
    sourceFileId: '7',
    originalName: 'file.pdf',
  }])
  const manifest = JSON.parse(await readFile(join(snapshot, 'manifest.json'), 'utf8'))
  await writeFile(join(workspace, 'source.json'), `${JSON.stringify({
    listId: manifest.source.listId,
    snapshotFingerprint: manifest.files['elements.jsonl'].sha256,
  })}\n`)
  return { snapshot, workspace }
}

function checkpointRecord(status = 'downloaded') {
  const contents = 'historical document'
  return `${JSON.stringify({
    sourceFileId: '7',
    status,
    bytes: Buffer.byteLength(contents),
    sha256: createHash('sha256').update(contents).digest('hex'),
  })}\n`
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
    source: { listId: 114 },
    files: {
      'elements.jsonl': {
        bytes: Buffer.byteLength(elementsContent),
        sha256: createHash('sha256').update(elementsContent).digest('hex'),
      },
    },
  }))
}
