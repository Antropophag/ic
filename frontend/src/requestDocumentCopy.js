import { personNameGenitive, positionGenitive } from './personNameGenitive'

/** Prepare a copy-only reference; stored and displayed author fields stay unchanged. */
export function requestReference(request) {
  const name = personNameGenitive(request.initiator)
  const position = positionGenitive(request.initiatorPosition)
  const author = [position.text, name.text].filter(Boolean).join(' ')
  const needsReview = name.needsReview || position.needsReview
  return {
    text: `Заявка №${request.id} от ${request.date}${author ? ` от ${author}` : ''}`,
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
