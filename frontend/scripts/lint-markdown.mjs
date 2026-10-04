import { fileURLToPath, pathToFileURL } from 'node:url'
import { lint } from 'markdownlint/promise'
import { glob } from 'tinyglobby'
import settings from '../../.markdownlint-cli2.mjs'

/** Run the existing repository rules and globs; return the number of violations. */
export async function lintMarkdown(cwd = fileURLToPath(new URL('../../', import.meta.url))) {
  const files = (await glob(settings.globs, { cwd, absolute: true, dot: true })).sort()
  if (!files.length) throw new Error('Markdown lint found no files')
  const result = await lint({ files, config: settings.config })
  const errors = Object.values(result).reduce((count, fileErrors) => count + fileErrors.length, 0)
  for (const [file, findings] of Object.entries(result)) {
    for (const finding of findings) {
      const detail = finding.errorDetail ? `: ${finding.errorDetail}` : ''
      console.error(`${file}:${finding.lineNumber} ${finding.ruleNames[0]} ${finding.ruleDescription}${detail}`)
    }
  }
  console.log(`Markdown: ${files.length} files, ${errors} errors`)
  return errors
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  process.exitCode = await lintMarkdown() ? 1 : 0
}
