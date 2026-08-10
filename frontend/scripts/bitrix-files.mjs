import { createHash, randomUUID } from 'node:crypto'
import { createReadStream, createWriteStream, existsSync } from 'node:fs'
import { appendFile, chmod, link, lstat, mkdir, open, readFile, readdir, rename, rm, stat, writeFile } from 'node:fs/promises'
import { basename, dirname, join, resolve } from 'node:path'
import { createInterface } from 'node:readline'
import { Readable, Transform } from 'node:stream'
import { pipeline } from 'node:stream/promises'
import { pathToFileURL } from 'node:url'
import process from 'node:process'
import { chromium } from '@playwright/test'

process.umask(0o077)

const ALLOWED_PATH_PREFIX = '/docs/file/FilesProposalTest/'
const DEFAULT_MAX_BYTES = 1024 * 1024 * 1024

export function parseArguments(argv) {
  const [command, ...rawOptions] = argv
  const options = {}
  for (const raw of rawOptions) {
    if (!raw.startsWith('--') || !raw.includes('=')) {
      throw new Error(`Invalid option: ${raw}`)
    }
    const [name, ...parts] = raw.slice(2).split('=')
    options[name] = parts.join('=')
  }
  return { command, options }
}

export function normalizeFileUrl(rawUrl, allowedHost) {
  // Legacy URLs contain unescaped file names; a literal # belongs to the path,
  // otherwise URL parsers silently treat the rest of the name as a fragment.
  // Two snapshot records also contain padding before the extension although
  // the legacy storage path has no such padding and returns 404 when it is kept.
  const correctedUrl = rawUrl
    .replace(/\s+(\.[^./?#]+)$/u, '$1')
    .replace('0060700001Е    (1).tif', '0060700001Е%2B%2B%2B (1).tif')
  const url = new URL(correctedUrl.replaceAll('#', '%23'))
  if (url.protocol !== 'https:') {
    throw new Error('File URL must use HTTPS.')
  }
  if (url.hostname !== allowedHost) {
    throw new Error('File URL host is outside the snapshot allowlist.')
  }
  if (!url.pathname.startsWith(ALLOWED_PATH_PREFIX)) {
    throw new Error('File URL path is outside the legacy file directory.')
  }
  if (url.username !== '' || url.password !== '') {
    throw new Error('File URL must not contain credentials.')
  }
  if (url.search !== '') {
    throw new Error('File URL must not contain query parameters.')
  }
  return url.toString()
}

export function plusSubstitutionCandidates(normalizedUrl) {
  const url = new URL(normalizedUrl)
  const prefix = url.pathname.slice(0, url.pathname.lastIndexOf('/') + 1)
  const name = decodeURIComponent(url.pathname.slice(prefix.length))
  const candidates = [url.toString()]
  for (let index = 0; index < name.length; ++index) {
    if (name[index] !== ' ') continue
    const candidateName = `${name.slice(0, index)}+${name.slice(index + 1)}`
    candidates.push(`${url.origin}${prefix}${encodeURIComponent(candidateName)}`)
  }
  return candidates
}

export async function readSnapshotFiles(snapshotDirectory) {
  const directory = resolve(snapshotDirectory)
  const manifestPath = join(directory, 'manifest.json')
  const elementsPath = join(directory, 'elements.jsonl')
  const manifest = JSON.parse(await readFile(manifestPath, 'utf8'))
  if (manifest.complete !== true || ![1, 2].includes(manifest.formatVersion)) {
    throw new Error('Snapshot is incomplete or unsupported.')
  }
  await verifySnapshotFile(directory, manifest, 'elements.jsonl')

  const associations = []
  const input = createInterface({ input: createReadStream(elementsPath), crlfDelay: Infinity })
  for await (const line of input) {
    const element = JSON.parse(line)
    if (typeof element.DETAIL_TEXT !== 'string') throw new Error(`Element ${element.ID} has no textual DETAIL_TEXT.`)
    let details
    try {
      details = JSON.parse(element.DETAIL_TEXT)
    } catch (error) {
      throw new Error(`Element ${element.ID} has malformed DETAIL_TEXT JSON.`, { cause: error })
    }
    for (const [type, files] of [
      ['supporting', details.supportingDocFiles ?? []],
      ['report', details.reportFiles ?? []],
    ]) {
      for (const file of files) {
        const sourceFileId = scalarString(file.id, 'file.id')
        if (!/^[0-9A-Za-z_-]+$/.test(sourceFileId)) throw new Error(`Unsafe source file identifier: ${sourceFileId}`)
        associations.push({
          requestNumber: scalarString(element.ID, 'element.ID'),
          documentType: type,
          sourceFileId,
          originalName: scalarString(file.name, 'file.name'),
          detailUrl: scalarString(file.detailURL, 'file.detailURL'),
        })
      }
    }
    for (const [commentType, comments] of [
      ['initiator', details.commentsInitiator ?? []],
      ['ic', details.commentsIC ?? []],
    ]) {
      for (const [commentIndex, comment] of comments.entries()) {
        const commentId = comment.id === undefined || comment.id === null || comment.id === ''
          ? `index-${commentIndex}`
          : scalarString(comment.id, 'comment.id')
        for (const file of comment.files ?? []) {
          const sourceFileId = scalarString(file.id, 'file.id')
          if (!/^[0-9A-Za-z_-]+$/.test(sourceFileId)) throw new Error(`Unsafe source file identifier: ${sourceFileId}`)
          associations.push({
            requestNumber: scalarString(element.ID, 'element.ID'),
            documentType: 'comment',
            commentType,
            sourceCommentId: commentId,
            sourceFileId,
            originalName: scalarString(file.name, 'file.name'),
            detailUrl: scalarString(file.detailURL, 'file.detailURL'),
          })
        }
      }
    }
  }
  if (associations.length === 0) {
    throw new Error('Snapshot contains no file associations.')
  }

  const allowedHost = new URL(associations[0].detailUrl).hostname
  const unique = new Map()
  for (const association of associations) {
    association.detailUrl = normalizeFileUrl(association.detailUrl, allowedHost)
    const existing = unique.get(association.sourceFileId)
    if (existing && (existing.detailUrl !== association.detailUrl || existing.originalName !== association.originalName)) {
      throw new Error(`Conflicting metadata for source file ${association.sourceFileId}.`)
    }
    unique.set(association.sourceFileId, association)
  }
  return { associations, uniqueFiles: [...unique.values()], allowedHost, manifest }
}

export async function loadCheckpoint(path) {
  const records = new Map()
  if (!existsSync(path)) return records
  const input = createInterface({ input: createReadStream(path), crlfDelay: Infinity })
  for await (const line of input) {
    if (line.trim() === '') continue
    const record = JSON.parse(line)
    records.set(String(record.sourceFileId), record)
  }
  return records
}

export async function downloadResponse({ response, destination, maxBytes }) {
  if (response.status >= 300 && response.status < 400) {
    throw new Error('AUTH_REQUIRED: file endpoint redirected to OAuth.')
  }
  if (response.status !== 200) {
    throw new Error(`HTTP_${response.status}`)
  }
  if (response.headers.get('content-type')?.toLowerCase().startsWith('text/html')) {
    throw new Error('AUTH_REQUIRED: file endpoint returned an HTML login page.')
  }
  const declaredLength = Number(response.headers.get('content-length'))
  if (Number.isFinite(declaredLength) && declaredLength > maxBytes) {
    throw new Error('FILE_TOO_LARGE')
  }
  if (!response.body) {
    throw new Error('EMPTY_RESPONSE_BODY')
  }

  return downloadNodeStream({
    stream: Readable.fromWeb(response.body),
    destination,
    maxBytes,
    declaredLength,
    mime: response.headers.get('content-type')?.split(';', 1)[0]?.trim() || 'application/octet-stream',
  })
}

async function downloadNodeStream({ stream, destination, maxBytes, declaredLength, mime }) {
  if (Number.isFinite(declaredLength) && declaredLength > maxBytes) throw new Error('FILE_TOO_LARGE')
  const temporary = `${destination}.partial`
  const hash = createHash('sha256')
  let bytes = 0
  const meter = new Transform({
    transform(chunk, encoding, callback) {
      bytes += chunk.length
      if (bytes > maxBytes) {
        callback(new Error('FILE_TOO_LARGE'))
        return
      }
      hash.update(chunk)
      callback(null, chunk)
    },
  })
  try {
    await pipeline(
      stream,
      meter,
      createWriteStream(temporary, { flags: 'wx', mode: 0o600 }),
    )
    await rename(temporary, destination)
  } catch (error) {
    await rm(temporary, { force: true })
    throw error
  }
  return {
    bytes,
    sha256: hash.digest('hex'),
    mime,
  }
}

async function auth(snapshotDirectory, workspace) {
  assertGraphicalSession()
  await preparePrivateWorkspace(workspace)
  const source = await readSnapshotFiles(snapshotDirectory)
  const profile = join(workspace, 'browser-profile')
  await mkdir(profile, { recursive: true, mode: 0o700 })
  const context = await chromium.launchPersistentContext(profile, {
    headless: false,
    acceptDownloads: true,
  })
  try {
    const page = context.pages()[0] ?? await context.newPage()
    try {
      await page.goto(source.uniqueFiles[0].detailUrl, { waitUntil: 'domcontentloaded', timeout: 30_000 })
    } catch (error) {
      process.stderr.write(`Первичная навигация не завершилась: ${error instanceof Error ? error.message : 'unknown error'}\n`)
    }
    process.stdout.write('Войдите в Bitrix24 и завершите OAuth в открытом окне. Затем вернитесь сюда и нажмите Enter.\n')
    await waitForEnter()
    await verifyBrowserFileAccess(page, source.uniqueFiles[0].detailUrl)
    process.stdout.write('OAuth-сессия проверена. Закрытый профиль готов к выгрузке.\n')
  } finally {
    await context.close()
    await protectTree(profile)
  }
}

async function download(snapshotDirectory, workspace, options) {
  await preparePrivateWorkspace(workspace)
  const source = await readSnapshotFiles(snapshotDirectory)
  const profile = join(workspace, 'browser-profile')
  if (!existsSync(profile)) throw new Error('Browser profile is missing; run auth first.')

  const objects = join(workspace, 'objects')
  const checkpointPath = join(workspace, 'checkpoint.jsonl')
  const associationsPath = join(workspace, 'associations.jsonl')
  await mkdir(objects, { recursive: true, mode: 0o700 })
  await writePrivateJsonLines(associationsPath, associationRecords(source))
  const checkpoint = await loadCheckpoint(checkpointPath)
  const maxBytes = positiveInteger(options['max-bytes'] ?? String(DEFAULT_MAX_BYTES), 'max-bytes')
  const limit = positiveInteger(options.limit ?? String(source.uniqueFiles.length), 'limit')
  const timeout = positiveInteger(options['timeout-ms'] ?? '60000', 'timeout-ms')

  const context = await chromium.launchPersistentContext(profile, { headless: true, acceptDownloads: true })
  const page = context.pages()[0] ?? await context.newPage()
  let downloaded = 0
  let skipped = 0
  let failed = 0
  let attempted = 0
  try {
    for (const file of source.uniqueFiles) {
      const destination = join(objects, file.sourceFileId)
      const previous = checkpoint.get(file.sourceFileId)
      if (previous?.status === 'downloaded' && existsSync(destination)) {
        await verifyDownloadedObject(destination, previous)
        ++skipped
        continue
      }
      if (existsSync(destination)) {
        throw new Error(`Object ${file.sourceFileId} exists without a valid downloaded checkpoint.`)
      }
      if (attempted >= limit) break
      ++attempted
      try {
        const metadata = await downloadWithBrowser(page, file.detailUrl, destination, maxBytes, timeout)
        const record = {
          sourceFileId: file.sourceFileId,
          status: 'downloaded',
          ...metadata,
          downloadedAt: new Date().toISOString(),
        }
        await appendCheckpoint(checkpointPath, record)
        checkpoint.set(file.sourceFileId, record)
        ++downloaded
      } catch (error) {
        const message = error instanceof Error ? error.message : 'UNKNOWN_ERROR'
        if (message.startsWith('AUTH_REQUIRED')) throw error
        await appendCheckpoint(checkpointPath, {
          sourceFileId: file.sourceFileId,
          status: 'failed',
          error: message,
          attemptedAt: new Date().toISOString(),
        })
        ++failed
      }
    }
  } finally {
    await context.close()
    await protectTree(workspace)
  }
  process.stdout.write(`${JSON.stringify({ downloaded, skipped, failed, uniqueFiles: source.uniqueFiles.length })}\n`)
}

export async function verifyWorkspace(snapshotDirectory, workspace) {
  const workspacePath = resolve(workspace)
  assertOutsideGit(workspacePath)
  const source = await readSnapshotFiles(snapshotDirectory)
  const checkpoint = await loadCheckpoint(join(workspacePath, 'checkpoint.jsonl'))
  const associationsPath = join(workspacePath, 'associations.jsonl')
  const expectedAssociations = `${associationRecords(source).map((record) => JSON.stringify(record)).join('\n')}\n`
  if (await readFile(associationsPath, 'utf8') !== expectedAssociations) {
    throw new Error('Workspace associations do not match the snapshot.')
  }

  const objectsPath = join(workspacePath, 'objects')
  const objectNames = (await readdir(objectsPath)).sort()
  const expectedNames = source.uniqueFiles.map(({ sourceFileId }) => sourceFileId).sort()
  if (JSON.stringify(objectNames) !== JSON.stringify(expectedNames)) {
    throw new Error('Workspace objects do not match the snapshot file identifiers.')
  }
  if (JSON.stringify([...checkpoint.keys()].sort()) !== JSON.stringify(expectedNames)) {
    throw new Error('Workspace checkpoint does not match the snapshot file identifiers.')
  }
  for (const file of source.uniqueFiles) {
    const record = checkpoint.get(file.sourceFileId)
    if (record?.status !== 'downloaded') {
      throw new Error(`File ${file.sourceFileId} has no successful checkpoint.`)
    }
    await verifyDownloadedObject(join(objectsPath, file.sourceFileId), record)
  }
  return {
    uniqueFiles: source.uniqueFiles.length,
    associations: source.associations.length,
    verified: source.uniqueFiles.length,
  }
}

function associationRecords(source) {
  return source.associations.map((association) => {
    const record = { ...association }
    delete record.detailUrl
    return record
  })
}

async function downloadWithBrowser(page, url, destination, maxBytes, timeout) {
  let browserDownload
  try {
    const downloadLink = await findLegacyDownloadLink(page, url, timeout)
    const downloadPromise = page.waitForEvent('download', { timeout })
    await downloadLink.click({ force: true })
    browserDownload = await downloadPromise
    const stream = await browserDownload.createReadStream()
    if (!stream) throw new Error('EMPTY_RESPONSE_BODY')
    const metadata = await downloadNodeStream({
      stream,
      destination,
      maxBytes,
      declaredLength: Number.NaN,
      mime: 'application/octet-stream',
    })
    const failure = await browserDownload.failure()
    if (failure) {
      await rm(destination, { force: true })
      throw new Error(`BROWSER_DOWNLOAD_FAILED: ${failure}`)
    }
    return metadata
  } catch (error) {
    if (page.url().includes('/oauth/authorize/')) {
      throw new Error('AUTH_REQUIRED: browser was redirected to OAuth.', { cause: error })
    }
    throw error
  } finally {
    if (browserDownload) await browserDownload.delete().catch(() => {})
  }
}

export async function findLegacyDownloadLink(page, url, timeout) {
  for (const candidate of plusSubstitutionCandidates(url)) {
    const response = await openLegacyFilePage(page, candidate, timeout)
    const downloadLink = page.locator('a.disk-detail-sidebar-editor-item-download')
    if (response?.status() === 200) {
      await downloadLink.waitFor({ state: 'attached', timeout })
      return downloadLink
    }
    if (![403, 404].includes(response?.status())) {
      throw new Error(`FILE_PAGE_HTTP_${response?.status() ?? 'UNKNOWN'}`)
    }
  }
  throw new Error('FILE_PAGE_NOT_FOUND')
}

async function verifyBrowserFileAccess(page, url) {
  await findLegacyDownloadLink(page, url, 30_000)
}

async function openLegacyFilePage(page, url, timeout) {
  const response = await page.goto(url, { waitUntil: 'domcontentloaded', timeout })
  if (page.url().includes('/oauth/authorize/')) {
    throw new Error('AUTH_REQUIRED: browser was redirected to OAuth.')
  }
  return response
}

async function verifySnapshotFile(directory, manifest, name) {
  const expected = manifest.files?.[name]
  if (!expected || typeof expected.sha256 !== 'string' || !Number.isInteger(expected.bytes)) {
    throw new Error(`Snapshot manifest metadata is missing for ${name}.`)
  }
  const path = join(directory, name)
  const fileStat = await stat(path)
  const hash = createHash('sha256')
  for await (const chunk of createReadStream(path)) hash.update(chunk)
  if (fileStat.size !== expected.bytes || hash.digest('hex') !== expected.sha256) {
    throw new Error(`Snapshot integrity check failed for ${name}.`)
  }
}

async function verifyDownloadedObject(path, checkpoint) {
  if (!Number.isInteger(checkpoint.bytes) || typeof checkpoint.sha256 !== 'string') {
    throw new Error('Downloaded checkpoint has no integrity metadata.')
  }
  const fileStat = await lstat(path)
  if (fileStat.isSymbolicLink() || !fileStat.isFile()) {
    throw new Error(`Downloaded object is not a regular file: ${checkpoint.sourceFileId}.`)
  }
  if (fileStat.size !== checkpoint.bytes) {
    throw new Error(`Downloaded object integrity check failed for ${checkpoint.sourceFileId}.`)
  }
  const hash = createHash('sha256')
  for await (const chunk of createReadStream(path)) hash.update(chunk)
  if (hash.digest('hex') !== checkpoint.sha256) {
    throw new Error(`Downloaded object integrity check failed for ${checkpoint.sourceFileId}.`)
  }
}

async function preparePrivateWorkspace(workspace) {
  const path = resolve(workspace)
  assertOutsideGit(path)
  await mkdir(path, { recursive: true, mode: 0o700 })
  await chmod(path, 0o700)
}

export function assertOutsideGit(path) {
  let current = resolve(path)
  while (true) {
    if (existsSync(join(current, '.git'))) throw new Error('Workspace must be outside a Git working tree.')
    const parent = dirname(current)
    if (parent === current) return
    current = parent
  }
}

export async function writePrivateJsonLines(path, records) {
  const contents = records.map((record) => JSON.stringify(record)).join('\n') + '\n'
  if (existsSync(path)) {
    if (await readFile(path, 'utf8') === contents) {
      try {
        await cleanupPublishedPartials(path)
      } catch (error) {
        process.stderr.write(`Не удалось очистить partial-файлы ${path}: ${error instanceof Error ? error.message : 'unknown error'}\n`)
      }
      return
    }
    throw new Error(`Existing ${path} does not match the current snapshot.`)
  }
  const temporary = `${path}.${process.pid}.${randomUUID()}.partial`
  try {
    await writeFile(temporary, contents, { mode: 0o600, flag: 'wx' })
    try {
      await link(temporary, path)
    } catch (error) {
      if (error?.code === 'EEXIST') {
        if (await readFile(path, 'utf8') === contents) return
        throw new Error(`Existing ${path} does not match the current snapshot.`, { cause: error })
      }
      throw error
    }
  } finally {
    await rm(temporary, { force: true })
  }
}

async function cleanupPublishedPartials(path) {
  const published = await stat(path)
  const directory = dirname(path)
  const name = basename(path)
  for (const entry of await readdir(directory)) {
    const legacyPartial = entry === `${name}.partial`
    const uniquePartial = entry.startsWith(`${name}.`) && entry.endsWith('.partial')
    if (!legacyPartial && !uniquePartial) continue
    const candidate = join(directory, entry)
    if (legacyPartial) {
      await rm(candidate, { force: true })
      continue
    }
    try {
      const candidateStat = await stat(candidate)
      if (candidateStat.dev === published.dev && candidateStat.ino === published.ino) {
        await rm(candidate, { force: true })
      }
    } catch (error) {
      if (error?.code !== 'ENOENT') throw error
    }
  }
}

function scalarString(value, name) {
  if (typeof value === 'string') return value
  if (typeof value === 'number' && Number.isFinite(value)) return String(value)
  throw new Error(`Snapshot field ${name} must be a string or a finite number.`)
}

async function appendCheckpoint(path, record) {
  await appendFile(path, `${JSON.stringify(record)}\n`, { mode: 0o600 })
  const handle = await open(path, 'r')
  try {
    await handle.sync()
  } finally {
    await handle.close()
  }
}

async function protectTree(path) {
  if (!existsSync(path)) return
  const current = await stat(path)
  await chmod(path, current.isDirectory() ? 0o700 : 0o600)
  if (!current.isDirectory()) return
  for (const entry of await readdir(path)) await protectTree(join(path, entry))
}

function positiveInteger(value, name) {
  const parsed = Number(value)
  if (!Number.isSafeInteger(parsed) || parsed < 1) throw new Error(`--${name} must be a positive integer.`)
  return parsed
}

function assertGraphicalSession() {
  if (process.platform === 'linux' && !process.env.DISPLAY && !process.env.WAYLAND_DISPLAY) {
    throw new Error('Graphical session is unavailable; run auth from a desktop terminal with DISPLAY or WAYLAND_DISPLAY.')
  }
}

async function waitForEnter() {
  const input = createInterface({ input: process.stdin, output: process.stdout })
  await new Promise((resolvePromise) => input.question('', resolvePromise))
  input.close()
}

async function main() {
  const { command, options } = parseArguments(process.argv.slice(2))
  if (!['auth', 'download', 'verify'].includes(command)) {
    throw new Error('Usage: bitrix-files <auth|download|verify> --snapshot=PATH --workspace=PATH')
  }
  if (!options.snapshot || !options.workspace) {
    throw new Error('--snapshot and --workspace are required.')
  }
  if (command === 'auth') await auth(options.snapshot, options.workspace)
  else if (command === 'download') await download(options.snapshot, options.workspace, options)
  else process.stdout.write(`${JSON.stringify(await verifyWorkspace(options.snapshot, options.workspace))}\n`)
}

if (process.argv[1] && import.meta.url === pathToFileURL(resolve(process.argv[1])).href) {
  main().catch((error) => {
    process.stderr.write(`${error instanceof Error ? error.message : 'Unknown error'}\n`)
    process.exitCode = 1
  })
}
