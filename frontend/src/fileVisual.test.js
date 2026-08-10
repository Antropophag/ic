import { describe, expect, it } from 'vitest'
import { fileVisualKey } from './fileVisual'

describe('fileVisualKey', () => {
  it.each([
    ['application/pdf', 'report.bin', 'pdf'],
    ['application/msword', 'report.bin', 'doc'],
    ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'report.bin', 'docx'],
    ['application/vnd.ms-excel', 'report.bin', 'xls'],
    ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'report.bin', 'xlsx'],
    ['image/jpeg', 'photo.bin', 'image'],
    ['application/zip', 'documents.bin', 'zip'],
    ['application/vnd.rar', 'documents.bin', 'rar'],
  ])('maps %s to %s', (mimeType, originalName, expected) => {
    expect(fileVisualKey({ mimeType, originalName })).toBe(expected)
  })

  it('prefers a recognized MIME type over a misleading extension', () => {
    expect(fileVisualKey({
      mimeType: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
      originalName: 'protocol.pdf',
    })).toBe('docx')
  })

  it('uses extension fallback for generic MIME types', () => {
    expect(fileVisualKey({ mimeType: 'application/octet-stream', originalName: 'scan.PNG' })).toBe('png')
    expect(fileVisualKey({ mimeType: 'application/octet-stream', originalName: 'archive.7z' })).toBe('generic')
  })
})
