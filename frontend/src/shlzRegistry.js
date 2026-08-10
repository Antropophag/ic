const STATUS_TONE_CLASSES = Object.freeze({
  blue: 'shlz-status--source-blue',
  cyan: 'shlz-status--cyan',
  orange: 'shlz-status--orange',
  violet: 'shlz-status--purple',
  green: 'shlz-status--green',
  red: 'shlz-status--ic-danger',
  gray: 'shlz-status--neutral',
  yellow: 'shlz-status--ic-warning',
})

export function shlzStatusToneClass(tone) {
  return STATUS_TONE_CLASSES[tone] || 'shlz-status--neutral'
}
