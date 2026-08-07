import { readFileSync } from 'node:fs'
import { describe, expect, it } from 'vitest'

const styles = readFileSync(new URL('./styles.css', import.meta.url), 'utf8')
const adminStyles = readFileSync(new URL('./admin.css', import.meta.url), 'utf8')
const helpStyles = readFileSync(new URL('../public/help/help.css', import.meta.url), 'utf8')
const compactStyles = styles.replace(/\s+/g, '')
const compactAdminStyles = adminStyles.replace(/\s+/g, '')
const compactHelpStyles = helpStyles.replace(/\s+/g, '')

describe('readable typography scale', () => {
  it('keeps primary interface text at the enlarged scale', () => {
    expect(compactStyles).toContain(
      '.primary,.secondary,.upload{height:40px;min-height:40px;display:inline-flex;align-items:center;justify-content:center;gap:8px;border-radius:20px;padding:016px;font-size:14.5px;line-height:22px',
    )
    expect(compactStyles).toContain(
      '.registryth{height:40px;padding:014px;border-bottom:1pxsolidvar(--line);background:var(--surface-subtle);color:#6f7b8e;font-size:11px;line-height:15.5px',
    )
    expect(compactStyles).toContain(
      '.request-page.entryp{display:block;margin-top:5px;overflow:visible;font-size:12px;line-height:18px',
    )
    expect(compactAdminStyles).toContain(
      '.admin-tabletd{height:52px;padding:7px14px;border-top:0;border-bottom:1pxsolidvar(--line-soft);color:#263348;font-size:13px;line-height:19px',
    )
    expect(compactHelpStyles).toContain('main>p:first-of-type{margin:0026px;color:var(--muted);font-size:14px;}')
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
