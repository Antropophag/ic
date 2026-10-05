import { expect, it } from 'vitest'
import { allObjectNamesText, requestReference, requestReferenceText } from './requestDocumentCopy'

it('copies names separated by commas followed by the supplier, without quantities', () => {
  expect(allObjectNamesText([{ name: 'Объект А', sampleQuantityText: '4 шт.' }, { name: 'Объект Б' }], 'Завод')).toBe('Объект А, Объект Б. Поставщик Завод')
})

it('copies number, date, position and name in the requested order', () => {
  expect(requestReferenceText({ id: '000123', date: '05.10.2026', initiatorPosition: 'Инженер', initiator: 'Тестов Иван Иванович', department: 'Отдел' })).toBe('Заявка №000123 от 05.10.2026 от инженера Тестова Ивана Ивановича')
})

it('handles missing fields without undefined values or an empty final preposition', () => {
  expect(requestReferenceText({ id: '000123', date: '05.10.2026' })).toBe('Заявка №000123 от 05.10.2026')
  expect(allObjectNamesText([{ name: 'Объект' }], null)).toBe('Объект. Поставщик не указан')
})

it('preserves ambiguous names and explains the review needed after copying', () => {
  const request = { id: '000123', date: '05.10.2026', initiator: 'Саша Ким', initiatorPosition: 'Инженер' }
  expect(requestReference(request)).toEqual({
    text: 'Заявка №000123 от 05.10.2026 от инженера Саша Ким',
    success: 'Заявка скопирована. Проверьте склонение ФИО и должности: неоднозначные значения оставлены в исходном написании.',
  })
  expect(request.initiator).toBe('Саша Ким')
})
