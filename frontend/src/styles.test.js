import { readFileSync } from 'node:fs'
import { describe, expect, it } from 'vitest'

const styles = readFileSync(new URL('./styles.css', import.meta.url), 'utf8')
const compactStyles = styles.replace(/\s+/g, '')

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

describe('request status facts', () => {
  it('centers the security mark with the shared inline-flex icon layout', () => {
    expect(compactStyles).toContain('.fact>span{display:block;')
    expect(compactStyles).toContain('.security-mark-icon{display:inline-flex;align-items:center;justify-content:center;')
  })
})
