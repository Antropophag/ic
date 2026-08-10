import '@fontsource/golos-text/cyrillic-400.css'
import '@fontsource/golos-text/cyrillic-500.css'
import '@fontsource/golos-text/cyrillic-600.css'
import '@shlz/styles'
import iconSpriteUrl from '@shlz/icons/sprite.svg?url'
import fileCsvUrl from '@shlz/icons/file-types/file-csv.svg?url'
import fileDocUrl from '@shlz/icons/file-types/file-doc.svg?url'
import fileDocxUrl from '@shlz/icons/file-types/file-docx.svg?url'
import fileGenericUrl from '@shlz/icons/file-types/file-generic.svg?url'
import fileImageUrl from '@shlz/icons/file-types/file-img.svg?url'
import filePdfUrl from '@shlz/icons/file-types/file-pdf-default.svg?url'
import filePngUrl from '@shlz/icons/file-types/file-png.svg?url'
import fileRarUrl from '@shlz/icons/file-types/file-rar.svg?url'
import fileXlsUrl from '@shlz/icons/file-types/file-xls.svg?url'
import fileXlsxUrl from '@shlz/icons/file-types/file-xlsx.svg?url'
import fileZipUrl from '@shlz/icons/file-types/file-zip.svg?url'
import './shlz-demo.css'

const fileIconUrls = Object.freeze({
  csv: fileCsvUrl,
  doc: fileDocUrl,
  docx: fileDocxUrl,
  generic: fileGenericUrl,
  image: fileImageUrl,
  pdf: filePdfUrl,
  png: filePngUrl,
  rar: fileRarUrl,
  xls: fileXlsUrl,
  xlsx: fileXlsxUrl,
  zip: fileZipUrl,
})

export async function enhanceRegistryTooltips(scope) {
  const { enhanceTooltips } = await import('@shlz/behaviors/tooltip')
  return enhanceTooltips(scope)
}

export function createUiMode() {
  return { iconSpriteUrl, fileIconUrls, enhanceRegistryTooltips }
}
