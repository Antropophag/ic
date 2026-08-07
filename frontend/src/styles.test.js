import { readFileSync } from 'node:fs'
import { describe, expect, it } from 'vitest'

const styles = readFileSync(new URL('./styles.css', import.meta.url), 'utf8')
const adminStyles = readFileSync(new URL('./admin.css', import.meta.url), 'utf8')
const helpStyles = readFileSync(new URL('../public/help/help.css', import.meta.url), 'utf8')
const compactStyles = styles.replace(/\s+/g, '')
const compactAdminStyles = adminStyles.replace(/\s+/g, '')
const compactHelpStyles = helpStyles.replace(/\s+/g, '')

describe('default interface scale', () => {
  it('matches 110% browser zoom and advances responsive thresholds accordingly', () => {
    expect(compactStyles).toContain(':root{zoom:1.1;')
    expect(compactStyles).toContain('@media(max-width:1298px)')
    expect(compactStyles).toContain('@media(max-width:1078px)')
    expect(compactStyles).toContain('@media(max-width:990px)')
    expect(compactStyles).toContain('@media(max-width:770px)')
    expect(compactAdminStyles).toContain('@media(max-width:1078px)')
    expect(compactAdminStyles).toContain('@media(max-width:770px)')
    expect(compactHelpStyles).toContain(':root{zoom:1.1;')
    expect(compactHelpStyles).toContain('@media(max-width:550px)')
  })
})

describe('registry row highlights', () => {
  const hoverGradients = {
    red: '#fbdedb',
    orange: '#fbe6d3',
    blue: '#dde3f4',
    violet: '#eeddf0',
    green: '#ddefe0',
  }

  it.each(Object.entries(hoverGradients))('keeps the %s row gradient on hover', (color, shade) => {
    expect(compactStyles).toContain(
      `.row-color-${color}:hover{background:linear-gradient(toright,${shade},#fff65%)}`,
    )
  })
})
