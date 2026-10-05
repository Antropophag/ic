import nameRules from './vendor/petrovich/rules.json'
import genderRules from './vendor/petrovich/gender.json'
import givenNames from './vendor/given-names/index.json'

const knownGivenNames = new Set(givenNames.map(name => name.toLocaleLowerCase('ru')))
const isKnownGivenName = value => value.split(/[-‐‑]/u).every(part => knownGivenNames.has(part.toLocaleLowerCase('ru')))

const families = /(?:ов|ев|ёв|ин|ын|ова|ева|ёва|ина|ына|ский|цкий|ской|цкой|ская|цкая|ый|ой|ая|яя|ко|их|ых)$/iu
const patronymic = /(?:ович|евич|ич|овна|евна|ична)$/iu
const nameWord = /^[А-ЯЁа-яё]+(?:[-‐‑][А-ЯЁа-яё]+)*$/u

function grammaticalGender(value, kind) {
  const word = value.toLocaleLowerCase('ru')
  const rules = genderRules.gender[kind]
  for (const [gender, words] of Object.entries(rules.exceptions || {})) {
    if (words.includes(word)) return gender
  }
  const matches = Object.entries(rules.suffixes || {}).flatMap(([gender, endings]) => endings.filter(ending => word.endsWith(ending)).map(ending => ({ gender, length: ending.length })))
  const longest = Math.max(0, ...matches.map(match => match.length))
  const genders = new Set(matches.filter(match => match.length === longest).map(match => match.gender))
  return genders.size === 1 ? [...genders][0] : null
}

function inflectPart(value, kind, gender) {
  const parts = value.split(/([-‐‑])/u)
  return parts.map((word, index) => {
    if (index % 2) return word
    const lower = word.toLocaleLowerCase('ru')
    const rules = nameRules[kind]
    const matches = (rule, exact) => (rule.gender === gender || rule.gender === 'androgynous')
      && (!rule.tags?.length || (index === 0 && parts.length > 1 && rule.tags.includes('first_word')))
      && rule.test.some(test => exact ? lower === test : lower.endsWith(test))
    const rule = rules.exceptions?.find(item => matches(item, true)) || rules.suffixes.find(item => matches(item, false))
    if (!rule) return word
    let result = word
    // Petrovich's first modification is genitive: '-' removes a letter, '.' keeps it.
    for (const character of rule.mods[0]) {
      if (character === '-') result = result.slice(0, -1)
      else if (character !== '.') result += character
    }
    return word === word.toLocaleUpperCase('ru') ? result.toLocaleUpperCase('ru') : result
  }).join('')
}

function candidate(parts, kinds) {
  const genders = new Set(parts.map((part, index) => grammaticalGender(part, kinds[index])).filter(gender => gender === 'male' || gender === 'female'))
  if (genders.size > 1) return []
  const possible = genders.size ? [...genders] : ['male', 'female']
  return possible.map(gender => parts.map((part, index) => inflectPart(part, kinds[index], gender)).join(' '))
}

/** Inflect only when all plausible parses agree; preserve ambiguous names verbatim. */
export function personNameGenitive(value) {
  const original = value?.trim() || ''
  if (!original) return { text: original, needsReview: false }
  const parts = original.split(/\s+/u)
  if (![2, 3].includes(parts.length) || !parts.every(part => nameWord.test(part))) return { text: original, needsReview: true }
  let orders
  if (parts.length === 3) {
    const middles = [1, 2].filter(index => patronymic.test(parts[index]))
    if (middles.length !== 1) return { text: original, needsReview: true }
    orders = middles[0] === 1
      ? [['firstname', 'middlename', 'lastname']]
      : [['lastname', 'firstname', 'middlename'], ['firstname', 'lastname', 'middlename']]
  } else {
    orders = [['firstname', 'lastname'], ['lastname', 'firstname']]
  }
  const knownFirstIndexes = parts.map((part, index) => isKnownGivenName(part) ? index : -1).filter(index => index >= 0)
  if (knownFirstIndexes.length === 1) orders = orders.filter(order => order[knownFirstIndexes[0]] === 'firstname')
  const familyIndexes = parts.map((part, index) => families.test(part) ? index : -1).filter(index => index >= 0)
  if (familyIndexes.length === 1) orders = orders.filter(order => order[familyIndexes[0]] === 'lastname')
  const variants = new Set(orders.flatMap(order => candidate(parts, order)))
  return variants.size === 1 ? { text: [...variants][0], needsReview: false } : { text: original, needsReview: true }
}

const positions = {
  сотрудник: 'сотрудника', специалист: 'специалиста', инженер: 'инженера',
  руководитель: 'руководителя', начальник: 'начальника', директор: 'директора',
  заместитель: 'заместителя', исполнитель: 'исполнителя', эксперт: 'эксперта',
  администратор: 'администратора', бухгалтер: 'бухгалтера', мастер: 'мастера',
  технолог: 'технолога', конструктор: 'конструктора', программист: 'программиста',
  техник: 'техника', механик: 'механика', электромеханик: 'электромеханика',
  лаборант: 'лаборанта', контролер: 'контролера', контролёр: 'контролёра',
  инспектор: 'инспектора', менеджер: 'менеджера', консультант: 'консультанта',
  секретарь: 'секретаря', заведующий: 'заведующего', заведующая: 'заведующей',
}
const modifiers = {
  главный: 'главного', главная: 'главной', ведущий: 'ведущего', ведущая: 'ведущей',
  старший: 'старшего', старшая: 'старшей', младший: 'младшего', младшая: 'младшей',
  генеральный: 'генерального', генеральная: 'генеральной',
  технический: 'технического', техническая: 'технической',
}

/** Inflect simple recognized jobs; preserve phrases that require grammatical parsing. */
export function positionGenitive(value) {
  const original = value?.trim() || ''
  if (!original) return { text: original, needsReview: false }
  if (/[–—/;,]/u.test(original)) return { text: original, needsReview: true }
  const words = original.split(/\s+/u)
  const result = []
  let index = 0
  while (index < words.length && Object.hasOwn(modifiers, words[index].toLocaleLowerCase('ru'))) {
    result.push(modifiers[words[index].toLocaleLowerCase('ru')])
    index += 1
  }
  const head = words[index]?.toLocaleLowerCase('ru').split('-') || []
  if (!head.length || head.some(word => !Object.hasOwn(positions, word))) return { text: original, needsReview: true }
  const tail = words.slice(index + 1)
  // Unknown tails may contain postposed adjectives, even when written in capitals.
  if (tail.length && !(tail.length === 1 && ['ИЦ', 'ОТК'].includes(tail[0]))) {
    return { text: original, needsReview: true }
  }
  result.push(head.map(word => positions[word]).join('-'), ...tail)
  return { text: result.join(' '), needsReview: false }
}
