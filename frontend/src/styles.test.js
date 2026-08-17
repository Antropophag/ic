import { readFileSync } from 'node:fs'
import { describe, expect, it } from 'vitest'

const styles = readFileSync(new URL('./styles.css', import.meta.url), 'utf8')
const adminStyles = readFileSync(new URL('./admin.css', import.meta.url), 'utf8')
const helpStyles = readFileSync(new URL('../public/help/help.css', import.meta.url), 'utf8')
const authScreen = readFileSync(new URL('./components/AuthScreen.vue', import.meta.url), 'utf8')
const compactStyles = styles.replace(/\s+/g, '')
const compactAdminStyles = adminStyles.replace(/\s+/g, '')
const compactHelpStyles = helpStyles.replace(/\s+/g, '')

function relativeLuminance(hexColor) {
  const channels = hexColor.match(/[a-f\d]{2}/gi).map((channel) => Number.parseInt(channel, 16) / 255)
  const linear = channels.map((channel) => (
    channel <= 0.04045 ? channel / 12.92 : ((channel + 0.055) / 1.055) ** 2.4
  ))

  return (0.2126 * linear[0]) + (0.7152 * linear[1]) + (0.0722 * linear[2])
}

function contrastRatio(foreground, background) {
  const luminances = [relativeLuminance(foreground), relativeLuminance(background)].sort((a, b) => b - a)
  return (luminances[0] + 0.05) / (luminances[1] + 0.05)
}

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

  it('keeps secondary text readable on portal surfaces', () => {
    const muted = styles.match(/--muted:(#[a-f\d]{6})/i)?.[1]
    const helpMuted = helpStyles.match(/--muted:(#[a-f\d]{6})/i)?.[1]

    expect(muted).toBe('#5f6c80')
    expect(helpMuted).toBe(muted)
    expect(contrastRatio(muted, '#ffffff')).toBeGreaterThanOrEqual(4.5)
    expect(contrastRatio(muted, '#f3f5f9')).toBeGreaterThanOrEqual(4.5)
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

describe('authentication ribbon background', () => {
  it('preserves the project background and does not block or clip the login form', () => {
    const authScreenDeclarations = [...styles.matchAll(/\.auth-screen\s*\{([^}]*)\}/g)]
      .map((match) => match[1])
      .join(';')

    expect(authScreen).toContain('<AetherRibbonMesh :error="Boolean(loginError)" />')
    expect(compactStyles).toContain('.auth-screen{position:relative;isolation:isolate;background:#f3f5f9}')
    expect(compactStyles).toContain('.aether-ribbon-mesh{position:absolute;z-index:-1;inset:0;width:100%;height:100%;pointer-events:none}')
    expect(authScreenDeclarations).not.toMatch(/overflow(?:-[xy])?\s*:\s*(?:hidden|clip)/)
  })

})

describe('request process timeline', () => {
  it('fills only completed segments before the current stage', () => {
    expect(compactStyles).toContain('.process-timelineli.done{border-color:var(--blue)}')
    expect(compactStyles).not.toMatch(/li\.done,\.process-timelineli\.current\{border-color:/)
  })
})
