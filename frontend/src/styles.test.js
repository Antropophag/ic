import { readFileSync } from 'node:fs'
import { describe, expect, it } from 'vitest'

const styles = readFileSync(new URL('./styles.css', import.meta.url), 'utf8')

describe('registry row highlights', () => {
  it.each(['red', 'orange', 'blue', 'violet', 'green'])(
    'keeps the %s row gradient on hover',
    color => {
      expect(styles).toMatch(
        new RegExp(`\\.row-color-${color}:hover\\{background:linear-gradient\\(to right,[^}]+,#fff 65%\\)\\}`),
      )
    },
  )
})
