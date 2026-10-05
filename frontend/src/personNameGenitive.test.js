import { expect, it } from 'vitest'
import { personNameGenitive, positionGenitive } from './personNameGenitive'

it.each([
  ['Алексей Соколов', 'Алексея Соколова'],
  ['Тестов Иван Иванович', 'Тестова Ивана Ивановича'],
  ['Иван Петрович Сидоров', 'Ивана Петровича Сидорова'],
  ['Иванов Иван Иванович', 'Иванова Ивана Ивановича'],
  ['Елена Васильева', 'Елены Васильевой'],
  ['Логинова Марина Петровна', 'Логиновой Марины Петровны'],
  ['Толстой Лев Николаевич', 'Толстого Льва Николаевича'],
  ['Павел Иванов', 'Павла Иванова'],
  ['Никита Петров', 'Никиты Петрова'],
  ['Илья Петров', 'Ильи Петрова'],
  ['Любовь Иванова', 'Любови Ивановой'],
  ['Анна Черных', 'Анны Черных'],
  ['Иван Петренко', 'Ивана Петренко'],
  ['Саша Иванов', 'Саши Иванова'],
  ['Салтыков-Щедрин Михаил Евграфович', 'Салтыкова-Щедрина Михаила Евграфовича'],
  ['Анна-Мария Иванова-Петрова', 'Анны-Марии Ивановой-Петровой'],
  ['ИВАНОВ ИВАН ИВАНОВИЧ', 'ИВАНОВА ИВАНА ИВАНОВИЧА'],
])('inflects %s using pinned name rules', (name, expected) => {
  expect(personNameGenitive(name)).toEqual({ text: expected, needsReview: false })
})

it.each(['Саша Ким', 'John Smith', 'Иванов И.И.', 'Анна Иванов Иванович', 'Неизвестное', 'Али ибн Мухаммад аль Фараби'])('leaves an ambiguous or unsupported name intact: %s', name => {
  expect(personNameGenitive(name)).toEqual({ text: name, needsReview: true })
})

it.each([
  ['Сотрудник', 'сотрудника'],
  ['Руководитель ИЦ', 'руководителя ИЦ'],
  ['Ведущий инженер', 'ведущего инженера'],
  ['Ведущий инженер-конструктор', 'ведущего инженера-конструктора'],
])('inflects the recognized job head without altering dependent words: %s', (position, expected) => {
  expect(positionGenitive(position)).toEqual({ text: expected, needsReview: false })
})

it('flags unknown positions instead of guessing', () => {
  expect(positionGenitive('Chief Data Officer')).toEqual({ text: 'Chief Data Officer', needsReview: true })
  expect(positionGenitive('Начальник отдела — заместитель директора').needsReview).toBe(true)
})

it.each(['Инженер ведущий', 'Главный инженер и технический директор', 'Инженер и разработчик', 'Директор исполнительный', 'Инженер дежурный', 'ДИРЕКТОР ИСПОЛНИТЕЛЬНЫЙ', 'Главный специалист по закупкам', 'Заместитель генерального директора', 'Начальник отдела производственных закупок'])('preserves job phrases requiring a full parser: %s', position => {
  expect(positionGenitive(position)).toEqual({ text: position, needsReview: true })
})
