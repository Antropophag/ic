import { personNameGenitive, positionGenitive } from './personNameGenitive'

/** Share the displayed/copied reference without changing stored identity fields. */
export function requestReference(request) {
  const name = personNameGenitive(request.initiator)
  const position = positionGenitive(request.initiatorPosition)
  const author = [position.text, name.text].filter(Boolean).join(' ')
  const needsReview = name.needsReview || position.needsReview
  const reference = `Заявка №${request.id} от ${request.date}`
  const authorLine = author ? `от ${author}` : ''
  const text = authorLine ? [reference, authorLine].join('\n') : reference
  return {
    text,
    authorLine,
    success: needsReview
      ? 'Заявка скопирована. Проверьте склонение ФИО и должности: неоднозначные значения оставлены в исходном написании.'
      : 'Заявка с датой и инициатором скопирована',
  }
}

export function requestReferenceText(request) {
  return requestReference(request).text
}

export function allObjectNamesText(objects, supplier) {
  const names = objects.map(object => object.name?.trim() || 'Наименование не указано').join(', ')
  return `${names}. Поставщик ${supplier?.trim() || 'не указан'}`
}
