import { readFileSync } from 'node:fs'
import { describe, expect, it } from 'vitest'

const styles = readFileSync(new URL('./styles.css', import.meta.url), 'utf8')

describe('registry row highlights', () => {
  const hoverGradients = {
    red: '#fbdedb',
    orange: '#fbe6d3',
    blue: '#dde3f4',
    violet: '#eeddf0',
    green: '#ddefe0',
  }

  it.each(Object.entries(hoverGradients))('keeps the %s row gradient on hover', (color, shade) => {
    expect(styles).toContain(
      `.row-color-${color}:hover{background:linear-gradient(to right,${shade},#fff 65%)}`,
    )
  })
})
