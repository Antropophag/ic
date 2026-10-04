/** Keep keyboard scrolling available through table controls in WebKit as well as Chromium. */
export function scrollRegistryTable(box, event) {
  if (!box || event.altKey || event.ctrlKey || event.metaKey || event.shiftKey) return
  if (event.target.closest('input,select,textarea,[contenteditable=true]')) return
  const delta = {
    ArrowDown: [0, 40], ArrowUp: [0, -40], ArrowRight: [40, 0], ArrowLeft: [-40, 0],
    PageDown: [0, box.clientHeight], PageUp: [0, -box.clientHeight],
  }[event.key]
  if (!delta) return
  const top = box.scrollTop
  const left = box.scrollLeft
  box.scrollTop += delta[1]
  box.scrollLeft += delta[0]
  // At the table boundary, leave normal page scrolling available.
  if (top !== box.scrollTop || left !== box.scrollLeft) event.preventDefault()
}
