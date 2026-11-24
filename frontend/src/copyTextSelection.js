/** Copy during the click gesture on HTTP/intranet pages without Clipboard API access. */
export function copyTextSelection(text, container) {
  const activeElement = document.activeElement
  const selection = window.getSelection()
  const ranges = selection ? Array.from({ length: selection.rangeCount }, (_, index) => selection.getRangeAt(index).cloneRange()) : []
  const inputSelection = typeof activeElement?.selectionStart === 'number'
    ? [activeElement.selectionStart, activeElement.selectionEnd, activeElement.selectionDirection] : null
  const field = document.createElement('textarea')
  field.value = text
  field.readOnly = true
  field.tabIndex = -1
  field.style.cssText = 'position:fixed;top:0;left:0;width:1px;height:1px;opacity:0;'
  container.append(field)
  try {
    field.focus({ preventScroll: true })
    field.select()
    return typeof document.execCommand === 'function' && document.execCommand('copy')
  } catch {
    return false
  } finally {
    field.remove()
    activeElement?.focus({ preventScroll: true })
    if (inputSelection) activeElement.setSelectionRange(...inputSelection)
    if (selection) {
      selection.removeAllRanges()
      for (const range of ranges) selection.addRange(range)
    }
  }
}
