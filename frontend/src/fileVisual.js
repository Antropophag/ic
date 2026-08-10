const MIME_VISUALS = Object.freeze({
  'application/pdf': 'pdf',
  'application/msword': 'doc',
  'application/rtf': 'doc',
  'text/rtf': 'doc',
  'application/vnd.openxmlformats-officedocument.wordprocessingml.document': 'docx',
  'application/vnd.ms-excel': 'xls',
  'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet': 'xlsx',
  'text/csv': 'csv',
  'application/csv': 'csv',
  'image/png': 'png',
  'application/zip': 'zip',
  'application/x-zip-compressed': 'zip',
  'application/vnd.rar': 'rar',
  'application/x-rar-compressed': 'rar',
})

const EXTENSION_VISUALS = Object.freeze({
  pdf: 'pdf', doc: 'doc', docx: 'docx', rtf: 'doc', xls: 'xls', xlsx: 'xlsx',
  csv: 'csv', png: 'png', jpg: 'image', jpeg: 'image', webp: 'image', gif: 'image',
  svg: 'image', zip: 'zip', rar: 'rar',
})

export function fileVisualKey(document = {}) {
  const mimeType = String(document.mimeType || '').split(';', 1)[0].trim().toLowerCase()
  if (MIME_VISUALS[mimeType]) return MIME_VISUALS[mimeType]
  if (mimeType.startsWith('image/')) return 'image'

  const fileName = String(document.originalName || document.title || '')
  const extension = fileName.includes('.') ? fileName.split('.').at(-1).toLowerCase() : ''
  return EXTENSION_VISUALS[extension] || 'generic'
}
