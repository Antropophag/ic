import { fileURLToPath } from 'node:url'
import { lint } from 'markdownlint/promise'
import { glob } from 'tinyglobby'
import settings from '../../.markdownlint-cli2.mjs'

// Keep the existing rules and file scope without the vulnerable CLI glob dependency chain.
const cwd = fileURLToPath(new URL('../../', import.meta.url))
const files = (await glob(settings.globs, { cwd, absolute: true, dot: true })).sort()
if (!files.length) throw new Error('Markdown lint found no files')
const result = await lint({ files, config: settings.config })
const errors = Object.values(result).reduce((count, fileErrors) => count + fileErrors.length, 0)
if (errors) {
  for (const [file, findings] of Object.entries(result)) {
    for (const finding of findings) {
      console.error(`${file}:${finding.lineNumber} ${finding.ruleNames[0]} ${finding.ruleDescription}${finding.errorDetail ? `: ${finding.errorDetail}` : ''}`)
    }
  }
  process.exitCode = 1
}
console.log(`Markdown: ${files.length} files, ${errors} errors`)
