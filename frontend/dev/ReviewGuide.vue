<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { devApi } from '../src/api'
import { readReviewGuideProgress, writeReviewGuideProgress } from './review-guide'

const emit = defineEmits(['navigate', 'seed-demo'])
const saved = readReviewGuideProgress()
const completed = ref(new Set(saved.completed))
const context = ref(saved.context)
const feedbackBody = ref('')
const selectedChecks = ref(new Set())
const feedbackItems = ref([])
const feedbackLoading = ref(true)
const feedbackSending = ref(false)
const feedbackMessage = ref('')
const feedbackAbortController = new AbortController()
let feedbackLoadSequence = 0

const quickSteps = [
  ['quick-seed', 'Подготовьте демонстрационные данные', 'Переключитесь на Елену Васильеву — администратора портала — и нажмите «Заполнить данные».', 'seed'],
  ['quick-registry', 'Посмотрите реестр заявок', 'Оцените персональные очереди, вкладки, поиск и статусы. Понятно ли, с чего начать?', 'registry'],
  ['quick-card', 'Откройте заявку с документами', 'Найдите заявку из демонстрационной серии 005. В ней есть документы и история, а сотруднику СБ нужно принять решение.', 'registry'],
  ['quick-details', 'Изучите карточку заявки', 'Проверьте статус, участников, доступные действия, документы и ленту событий.', 'registry'],
  ['quick-role', 'Переключитесь на другого пользователя', 'Выберите Олега Воронцова — сотрудника СБ. Страница перезагрузится.', 'registry'],
  ['quick-actions', 'Сравните доступные действия', 'Снова найдите серию 005 и проверьте, какие действия теперь доступны сотруднику СБ.', 'registry'],
  ['quick-safe', 'Выполните действие без изменения статуса', 'Добавьте комментарий или скачайте доступный документ. Не завершайте заявку, если с ней работает другой проверяющий.', 'registry'],
  ['quick-impression', 'Запишите первое впечатление', 'Отметьте, что осталось непонятным и где было трудно найти следующий шаг.', 'none'],
]

const flowSteps = [
  ['flow-1', 'Сотрудник', 'Создание и регистрация', 'Откройте форму «Новая заявка». Проверьте обязательные поля и сохранение черновика. Чтобы пропустить создание, найдите серию 001.', '«Заявка зарегистрирована»', 'Понятно ли, какие поля нужно заполнить и что произойдёт после отправки?', '001'],
  ['flow-2', 'Руководитель ИЦ', 'Назначение исполнителя', 'Переключитесь на Александра Иванова. Откройте серию 001, выберите исполнителя и подтвердите назначение.', 'Статус остаётся «Заявка зарегистрирована»', 'Легко ли выбрать ответственного? Понятно ли, что назначение сохранено?', '001'],
  ['flow-3', 'Исполнитель ИЦ', 'Начало и проведение работ', 'Переключитесь на Дмитрия Петрова. Откройте назначенную заявку и начните работу. Чтобы сразу посмотреть этот этап, используйте серию 002.', '«Заявка в работе»', 'Понятно ли, что начать работу может только назначенный исполнитель?', '002'],
  ['flow-4', 'Исполнитель ИЦ', 'Загрузка отчёта', 'В серии 002 загрузите PDF-файл размером до 10 МБ. Готовый отчёт можно посмотреть в серии 004.', '«Подготовка заключения»', 'Понятны ли требования к файлу и результат загрузки?', '004'],
  ['flow-5', 'Эксперт', 'Подготовка заключения', 'Переключитесь на Анну Кузнецову. Найдите серию 004, возьмите заявку в работу и опубликуйте заключение.', '«Контроль СБ»', 'Достаточно ли сведений для заключения? Понятно ли, что система сама сформирует PDF-файл?', '004'],
  ['flow-6', 'Сотрудник СБ', 'Контроль заключения', 'Переключитесь на Михаила Попова. Откройте серию 005 и согласуйте заключение или верните его с пояснением.', 'После согласования — «Заявка выполнена», после возврата — «Подготовка заключения»', 'Понятны ли последствия каждого решения? Очевидно ли, что при возврате нужно указать причину?', '005'],
]

const roles = [
  ['Сотрудник', 'Создаёт заявки и следит за их выполнением.', 'Видит реестр, свои заявки, карточки заявок и доступные вложения.', 'Может создать или отозвать незавершённую заявку и добавить комментарий.', 'Начните с серии 001. Проверьте форму и вкладку «Мои заявки».'],
  ['Руководитель ИЦ', 'Организует работу испытательного центра.', 'Видит все заявки и очереди, в которых нужно назначить исполнителя или начать работу.', 'Может назначить или сменить исполнителя, управлять ходом работ, отказать в испытаниях и выбрать цветовую метку.', 'Начните с серий 001–003. Убедитесь, что решения СБ недоступны.'],
  ['Руководитель лаборатории', 'Организует работу лаборатории по заявкам.', 'Видит реестр заявок, карточки и документы процесса.', 'Может назначить исполнителя, управлять ходом работ, загрузить отчёт или отказать в испытаниях.', 'Сравните доступные действия с ролью руководителя ИЦ на сериях 001–003.'],
  ['Исполнитель ИЦ', 'Проводит испытания и готовит отчёт.', 'Видит назначенные ему заявки и очередь предстоящих действий.', 'Может начать, приостановить или возобновить работу, а также загрузить, заменить или удалить отчёт.', 'Начните с серий 002 и 003. Убедитесь, что действия по чужим заявкам недоступны.'],
  ['Эксперт', 'Проверяет отчёт и готовит экспертное заключение.', 'Видит заявки, ожидающие экспертизы, и отчёт по заявке, которую взял в работу.', 'Может взять или перехватить заявку, передать её другому эксперту и опубликовать заключение.', 'Начните с серии 004. Сравните карточку до и после взятия заявки в работу.'],
  ['Сотрудник СБ', 'Проверяет заключение и принимает решение по заявке.', 'Видит очередь заявок на контроль, заключение и историю процесса.', 'Может согласовать заключение и завершить заявку либо вернуть её с обязательным пояснением.', 'Начните с серии 005. После повторного заполнения демонстрационных данных проверьте второй вариант решения.'],
  ['Администратор', 'Управляет доступом и помогает разбирать события в системе.', 'Видит пользователей и роли, журнал действий и журнал уведомлений.', 'Может назначать роли, исправлять подразделение заявки и просматривать журналы. В версии для разработки также может заново заполнить демонстрационные данные.', 'Проверьте раздел администрирования и фильтры журналов. Не меняйте роли других проверяющих без согласования.'],
]

const extraScenarios = [
  ['Приостановка и возобновление работы', 'В серии 003 исполнитель или руководитель может возобновить работу. В серии 002 можно проверить приостановку.'],
  ['Возврат сотрудником СБ и повторный цикл', 'В серии 004 уже есть возврат. Серию 005 можно вернуть с новым пояснением.'],
  ['Отказ и отзыв', 'Серии 007 и 008 показывают завершённые ветки и причины в ленте.'],
  ['Комментарии и вложения', 'Добавьте к активной заявке комментарий и файл допустимого типа. После итогового решения добавление комментария должно стать недоступным.'],
  ['Версии и удаление отчёта', 'Исполнитель или руководитель может заменить и удалить отчёт. После удаления повторная загрузка запускает цикл экспертизы заново.'],
  ['Пустой список и ограничения доступа', 'Настройте фильтр без результатов. Затем откройте заявку от имени сотрудника, который в ней не участвует: недоступные действия и документы не должны отображаться.'],
]

const reviewGroups = [
  ['Логика процесса', ['Соответствует ли порядок действий вашим ожиданиям?', 'Понятно ли, кто отвечает за следующий этап?', 'Корректно ли меняется статус заявки?', 'Сохраняются ли данные заявки после возврата?']],
  ['Роли и права', ['Содержит ли очередь только задачи текущего пользователя?', 'Доступны ли пользователю необходимые действия?', 'Скрыты ли действия, недоступные этой роли?', 'Нельзя ли выполнить действие на неподходящем этапе?']],
  ['Интерфейс и тексты', ['Легко ли найти нужную заявку?', 'Легко ли найти документы и историю заявки?', 'Понятны ли названия статусов и кнопок?', 'Понятно ли сообщение об ошибке?']],
  ['Ошибки', ['Нет ли зависаний и пустых экранов?', 'Работают ли кнопки с первого нажатия?', 'Нет ли противоречий в данных заявки?', 'Нет ли обрезанного текста и визуальных дефектов?']],
]

const totalSteps = computed(() => quickSteps.length + flowSteps.length)
const doneCount = computed(() => [...completed.value].filter(id => id.startsWith('quick-') || id.startsWith('flow-')).length)
const progress = computed(() => Math.round((doneCount.value / totalSteps.value) * 100))

function persist(nextContext = context.value) {
  context.value = nextContext
  writeReviewGuideProgress({ completed: [...completed.value], context: nextContext })
}

function toggle(id) {
  const next = new Set(completed.value)
  next.has(id) ? next.delete(id) : next.add(id)
  completed.value = next
  persist()
}

function go(target, scenario, step, role = '', object = '') {
  if (target === 'seed') {
    emit('seed-demo')
    return
  }
  const nextContext = { scenario, step, role, object }
  persist(nextContext)
  emit('navigate', nextContext)
}

function reset() {
  completed.value = new Set()
  context.value = null
  writeReviewGuideProgress({ completed: [], context: null })
}

function toggleFeedbackCheck(item) {
  const next = new Set(selectedChecks.value)
  next.has(item) ? next.delete(item) : next.add(item)
  selectedChecks.value = next
}

async function loadFeedback() {
  const sequence = ++feedbackLoadSequence
  feedbackLoading.value = true
  try {
    const result = await devApi.reviewFeedback(feedbackAbortController.signal)
    if (feedbackAbortController.signal.aborted || sequence !== feedbackLoadSequence) return
    feedbackItems.value = result.items || []
  } catch (error) {
    if (error?.name === 'AbortError') return
    feedbackMessage.value = 'Не удалось загрузить замечания.'
  } finally {
    if (!feedbackAbortController.signal.aborted && sequence === feedbackLoadSequence) {
      feedbackLoading.value = false
    }
  }
}

async function submitFeedback() {
  const body = feedbackBody.value.trim()
  if (!body || feedbackSending.value) return
  feedbackLoadSequence++
  feedbackLoading.value = false
  feedbackSending.value = true
  feedbackMessage.value = ''
  try {
    const created = await devApi.createReviewFeedback(
      body,
      [...selectedChecks.value],
      feedbackAbortController.signal,
    )
    if (feedbackAbortController.signal.aborted) return
    feedbackItems.value = [created, ...feedbackItems.value]
    feedbackBody.value = ''
    selectedChecks.value = new Set()
    feedbackMessage.value = 'Спасибо, замечание сохранено.'
  } catch (error) {
    if (error?.name === 'AbortError') return
    feedbackMessage.value = 'Не удалось сохранить замечание. Повторите попытку.'
  } finally {
    if (!feedbackAbortController.signal.aborted) feedbackSending.value = false
  }
}

function feedbackDate(value) {
  return new Date(`${value.replace(' ', 'T')}Z`).toLocaleString('ru-RU', { dateStyle: 'short', timeStyle: 'short' })
}

onMounted(loadFeedback)
onBeforeUnmount(() => feedbackAbortController.abort())
</script>

<template>
  <div class="page review-guide">
    <section class="review-hero card">
      <div><p class="eyebrow">Предварительное ревью · версия для разработки</p><h2>Проверьте основной процесс</h2><p>Портал помогает провести заявку на испытания от регистрации до решения сотрудника СБ. Пройдите этот путь и отметьте всё, что мешает понять задачу, ответственность участников или следующий шаг.</p><div class="review-time"><span>Быстрый обзор · около 10 минут</span><span>Полный сценарий · от 25 до 40 минут</span></div></div>
      <div class="review-progress" :style="{ '--progress': `${progress}%` }"><strong>{{ progress }}%</strong><span>{{ doneCount }} из {{ totalSteps }} шагов</span><button class="review-link" type="button" @click="reset">Сбросить прогресс</button></div>
    </section>

    <aside class="review-warning"><b>Это версия для разработки</b><span>Здесь используются демонстрационные данные — их можно свободно менять. Некоторые интеграции и уведомления могут быть отключены или работать в тестовом режиме. Инструменты для проверки не войдут в промышленную версию. Записывайте всё, что мешает понять или проверить процесс.</span></aside>

    <aside v-if="context" class="review-resume card"><div><span>Вы вернулись из портала</span><b>{{ context.scenario }} · {{ context.step }}</b><small v-if="context.role">Нужная роль: {{ context.role }}<template v-if="context.object"> · объект: {{ context.object }}</template></small></div><button class="review-link" type="button" @click="persist(null)">Закрыть</button></aside>

    <nav class="review-nav card" aria-label="Разделы гайда"><a href="#prepare">Подготовка</a><a href="#roles">Роли</a><a href="#process">Процесс</a><a href="#quick">Быстрый обзор</a><a href="#full">Полный сценарий</a><a href="#checklist">Чек-лист</a><a href="#feedback">Обратная связь</a></nav>

    <details id="prepare" class="review-section card" open><summary><span>01</span><div><h3>Подготовьте демонстрационные данные</h3><p>Начните с исходного набора заявок.</p></div></summary><div class="review-section-body review-split"><div><p>Выберите в переключателе Елену Васильеву — <b>администратора портала</b>. Только администратору доступна кнопка «Заполнить данные».</p><ul><li>Система создаст 100 демонстрационных заявок во всех реализованных статусах, а также комментарии и файлы.</li><li>Функцию можно запустить повторно, но текущие заявки, комментарии, история и файлы будут удалены.</li><li>Пользователи и их роли не изменятся.</li><li>Отдельно восстановить изменения другого проверяющего нельзя. Если данные уже меняли, согласуйте повторное заполнение или продолжайте работу с текущим набором.</li></ul></div><div class="review-callout"><b>Повторное заполнение заменит все заявки</b><p>Перед запуском убедитесь, что другие коллеги сейчас не проходят сценарий в этом реестре.</p><button class="primary" type="button" @click="go('seed', '', 'Подготовка демонстрационных данных', 'Администратор')">Заполнить данные</button></div></div></details>

    <details id="roles" class="review-section card" open><summary><span>02</span><div><h3>Проверьте работу разных ролей</h3><p>Переключатель пользователей находится в шапке.</p></div></summary><div class="review-section-body"><p class="review-lead">Откройте одну заявку от имени разных участников процесса. Так вы увидите, как меняются доступные действия и ограничения. Гайд не переключает пользователя автоматически — выберите нужный профиль в шапке.</p><div class="role-grid"><article v-for="role in roles" :key="role[0]" class="role-card"><span class="role-dot"></span><h4>{{ role[0] }}</h4><b>{{ role[1] }}</b><p>{{ role[2] }}</p><p><strong>Доступные действия:</strong> {{ role[3] }}</p><small>{{ role[4] }}</small></article></div></div></details>

    <details id="process" class="review-section card" open><summary><span>03</span><div><h3>Основной процесс</h3><p>Последовательность этапов и возможные ответвления.</p></div></summary><div class="review-section-body"><ol class="process-map"><li><i>1</i><b>Сотрудник</b><span>Создаёт заявку</span><em><small>Статус</small>Заявка зарегистрирована</em></li><li><i>2</i><b>Руководитель</b><span>Назначает исполнителя</span><em><small>Статус</small>Заявка зарегистрирована</em></li><li><i>3</i><b>Исполнитель</b><span>Начинает работу и загружает отчёт</span><em><small>Статус</small>Подготовка заключения</em></li><li><i>4</i><b>Эксперт</b><span>Берёт заявку в работу и публикует заключение</span><em><small>Статус</small>Контроль СБ</em></li><li><i>5</i><b>Сотрудник СБ</b><span>Согласует заключение</span><em><small>Статус</small>Заявка выполнена</em></li></ol><div class="process-branches"><b>Другие варианты</b><span>Исполнитель ↔ приостановка и возобновление</span><span>Сотрудник СБ → возврат эксперту с пояснением → новое заключение</span><span>Руководитель → отказ</span><span>Инициатор → отзыв до решения СБ</span></div></div></details>

    <details id="quick" class="review-section card" open><summary><span>04</span><div><h3>Быстрый обзор · 10 минут</h3><p>Посмотрите главное и составьте первое впечатление. После проверки нажмите «Отметить шаг».</p></div></summary><div class="review-section-body"><ol class="guide-steps"><li v-for="(step, index) in quickSteps" :key="step[0]" :class="{ done: completed.has(step[0]) }"><button class="step-check" type="button" :aria-pressed="completed.has(step[0])" @click="toggle(step[0])"><span>{{ completed.has(step[0]) ? '✓' : index + 1 }}</span>{{ completed.has(step[0]) ? 'Выполнено' : 'Отметить шаг' }}</button><div><h4>{{ step[1] }}</h4><p>{{ step[2] }}</p></div><button v-if="step[3] !== 'none'" class="secondary" type="button" @click="go(step[3], 'Быстрый обзор', step[1])">{{ step[3] === 'seed' ? 'Заполнить данные' : 'Открыть реестр' }}</button></li></ol></div></details>

    <details id="full" class="review-section card" open><summary><span>05</span><div><h3>Полный сквозной сценарий</h3><p>Проведите одну заявку через весь процесс или начните с нужной демонстрационной серии. Каждый проверенный шаг отметьте отдельной кнопкой.</p></div></summary><div class="review-section-body"><ol class="guide-steps flow"><li v-for="(step, index) in flowSteps" :key="step[0]" :class="{ done: completed.has(step[0]) }"><button class="step-check" type="button" :aria-pressed="completed.has(step[0])" @click="toggle(step[0])"><span>{{ completed.has(step[0]) ? '✓' : index + 1 }}</span>{{ completed.has(step[0]) ? 'Выполнено' : 'Отметить шаг' }}</button><div><span class="badge blue">{{ step[1] }}</span><h4>{{ step[2] }}</h4><p><b>Действие:</b> {{ step[3] }}</p><p><b>Ожидаемый результат:</b> {{ step[4] }}</p><p class="review-question">Обратите внимание: {{ step[5] }}</p></div><button class="secondary" type="button" @click="go('flow', 'Полный сценарий', step[2], step[1], `демонстрационная серия ${step[6]}`)">Открыть реестр</button></li></ol></div></details>

    <details class="review-section card"><summary><span>06</span><div><h3>Проверка каждой роли</h3><p>Откройте карточки ролей выше и ответьте на вопросы.</p></div></summary><div class="review-section-body"><div class="review-question-grid"><p v-for="question in ['Понятно ли, какие заявки требуют внимания?', 'Видно ли, кто и когда завершил предыдущий этап?', 'Понятно ли, почему действие недоступно?', 'Достаточно ли данных для принятия решения?', 'Легко ли найти нужные документы?', 'Нельзя ли выполнить действие на неподходящем этапе?', 'Понятен ли результат нажатия кнопки?', 'Отличается ли реестр этой роли от реестра других участников?']" :key="question">{{ question }}</p></div></div></details>

    <details class="review-section card"><summary><span>07</span><div><h3>Дополнительные сценарии</h3><p>Эти варианты уже доступны в версии для разработки.</p></div></summary><div class="review-section-body scenario-grid"><article v-for="scenario in extraScenarios" :key="scenario[0]"><h4>{{ scenario[0] }}</h4><p>{{ scenario[1] }}</p><button class="review-link" type="button" @click="go('extra', 'Дополнительный сценарий', scenario[0])">Открыть реестр →</button></article></div></details>

    <details id="checklist" class="review-section card" open><summary><span>08</span><div><h3>Чек-лист ревью</h3><p>Отметьте пункты, связанные с замечанием: они сохранятся вместе с текстом.</p></div></summary><div class="review-section-body review-checklist"><section v-for="group in reviewGroups" :key="group[0]"><h4>{{ group[0] }}</h4><label v-for="item in group[1]" :key="item"><input type="checkbox" :checked="selectedChecks.has(item)" @change="toggleFeedbackCheck(item)">{{ item }}</label></section></div></details>

    <details id="feedback" class="review-section card" open><summary><span>09</span><div><h3>Оставьте обратную связь</h3><p>Замечание увидят все участники предварительного ревью.</p></div></summary><div class="review-section-body feedback-layout"><form class="feedback-form" @submit.prevent="submitFeedback"><label>Опишите замечание<textarea v-model="feedbackBody" maxlength="5000" required placeholder="Укажите роль, страницу, заявку, свои действия, ожидаемый результат и то, что произошло"></textarea></label><div class="feedback-actions"><button class="primary" :disabled="feedbackSending || !feedbackBody.trim()">{{ feedbackSending ? 'Сохранение…' : 'Оставить обратную связь' }}</button><span role="status">{{ feedbackMessage }}</span></div></form><section class="feedback-list"><h4>Последние замечания</h4><p v-if="feedbackLoading" class="feedback-empty">Загрузка…</p><p v-else-if="!feedbackItems.length" class="feedback-empty">Замечаний пока нет.</p><article v-for="item in feedbackItems" :key="item.id"><header><b>{{ item.authorName }}</b><time>{{ feedbackDate(item.createdAt) }}</time></header><p>{{ item.body }}</p><div v-if="item.checklist.length" class="feedback-tags"><span v-for="check in item.checklist" :key="check">{{ check }}</span></div></article></section></div></details>

    <details class="review-section card"><summary><span>10</span><div><h3>Что сейчас не проверяем</h3><p>Ограничения версии для разработки.</p></div></summary><div class="review-section-body"><ul><li>Фактическую доставку писем. Почтовый сервер может быть заменён имитацией, поэтому проверяйте запись и статус в журнале, а не получение письма адресатом.</li><li>Внешние интеграции и перенос данных из Bitrix24.</li><li>Переключение на второго эксперта через шапку. Этот профиль используется в демонстрационных данных и при переназначении, но отсутствует в переключателе пользователей.</li><li>Независимую работу нескольких проверяющих с разными наборами данных. Реестр общий, и повторное заполнение затрагивает всех.</li><li>Автоматическое открытие заявки по номеру демонстрационной серии. Откройте реестр и найдите заявку через поиск.</li></ul></div></details>
  </div>
</template>

<style scoped>
.review-guide{display:grid;gap:12px;padding-top:8px}.review-hero{display:grid;grid-template-columns:1fr 180px;gap:28px;padding:32px;background:linear-gradient(135deg,#fff 55%,#eef0f8)}.review-hero h2{max-width:760px;margin:5px 0 10px;font-size:30px;letter-spacing:-.02em}.review-hero p{max-width:760px;margin:0;color:#536075;line-height:1.55}.review-time{display:flex;gap:9px;margin-top:18px;flex-wrap:wrap}.review-time span,.process-branches span{border-radius:16px;background:#eef0f8;padding:7px 11px;color:#253d98;font-size:11px}.review-progress{width:154px;height:154px;align-self:center;justify-self:end;display:grid;place-content:center;border-radius:50%;background:radial-gradient(closest-side,#fff 78%,transparent 80% 99%),conic-gradient(#253d98 var(--progress),#dfe3eb 0);text-align:center}.review-progress strong{font-size:27px}.review-progress span{font-size:10px;color:#657286}.review-link{border:0;background:transparent;padding:5px;color:#253d98;font-size:11px}.review-warning{display:flex;gap:14px;align-items:flex-start;border:1px solid #f1d2aa;border-radius:12px;background:#fff8ed;padding:14px 18px;color:#6e4b1f;font-size:12px;line-height:18px}.review-warning b{white-space:nowrap}.review-nav{position:sticky;top:8px;z-index:5;display:flex;gap:4px;padding:7px;box-shadow:0 6px 24px rgba(11,22,35,.07);overflow:auto}.review-nav a{border-radius:17px;padding:8px 11px;color:#536075;font-size:11px;text-decoration:none;white-space:nowrap}.review-nav a:hover{background:#eef0f8;color:#253d98}.review-section{scroll-margin-top:60px;overflow:hidden}.review-section summary{display:flex;gap:14px;align-items:center;padding:18px 22px;cursor:pointer;list-style:none}.review-section summary::-webkit-details-marker{display:none}.review-section summary>span{width:34px;height:34px;display:grid;place-items:center;flex:none;border-radius:50%;background:#eef0f8;color:#253d98;font-size:11px;font-weight:600}.review-section summary h3{margin:0;font-size:16px}.review-section summary p{margin:3px 0 0;color:#8a96a7;font-size:11px}.review-section summary:after{content:'+';margin-left:auto;color:#657286;font-size:20px}.review-section[open] summary:after{content:'−'}.review-section-body{border-top:1px solid #e9edf2;padding:22px;line-height:1.55;font-size:13px}.review-section-body>p:first-child{margin-top:0}.review-section-body li+li{margin-top:7px}.review-split{display:grid;grid-template-columns:1fr 340px;gap:28px}.review-callout{align-self:start;border-radius:12px;background:#f8f9fb;padding:18px}.review-callout p{color:#657286}.review-lead{color:#536075}.role-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:10px}.role-card{position:relative;border:1px solid #e9edf2;border-radius:12px;padding:16px}.role-card h4{margin:0 0 7px}.role-card>b{font-size:11px}.role-card p{margin:8px 0;color:#536075;font-size:11px}.role-card small{display:block;border-top:1px solid #e9edf2;padding-top:9px;color:#253d98}.role-dot{position:absolute;right:14px;top:14px;width:8px;height:8px;border-radius:50%;background:#253d98}.process-map{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:28px;margin:0;padding:0;list-style:none}.process-map li{position:relative;min-height:154px;display:flex;flex-direction:column;border:1px solid #dfe4ed;border-radius:12px;background:#f8f9fb;padding:14px}.process-map li:not(:last-child):after{content:'→';position:absolute;z-index:2;right:-24px;top:50%;width:20px;color:#253d98;font-size:20px;line-height:20px;text-align:center;transform:translateY(-50%)}.process-map i{width:24px;height:24px;display:grid;place-items:center;margin-bottom:10px;border-radius:50%;background:#253d98;color:#fff;font-size:10px;font-style:normal;font-weight:600}.process-map b,.process-map span,.process-map em{display:block}.process-map b{font-size:12px}.process-map span{margin:5px 0 12px;color:#536075;font-size:11px;line-height:16px}.process-map em{margin-top:auto;border-top:1px solid #e1e5ec;padding-top:9px;color:#253d98;font-size:10px;line-height:14px;font-style:normal}.process-map em small{display:block;margin-bottom:2px;color:#8a96a7;font-size:8px;letter-spacing:.05em;text-transform:uppercase}.process-branches{display:flex;gap:7px;align-items:center;flex-wrap:wrap;margin-top:18px}.process-branches>b{margin-right:3px;font-size:11px}.guide-steps{display:grid;gap:8px;margin:0;padding:0;list-style:none}.guide-steps li{display:grid;grid-template-columns:118px 1fr auto;gap:14px;align-items:center;border:1px solid #e9edf2;border-radius:12px;padding:13px}.guide-steps li.done{border-color:#bcd8c1;background:#f2f8f3}.step-check{height:34px;display:inline-flex;align-items:center;gap:7px;border:1px solid #bfc8d5;border-radius:17px;background:#fff;padding:0 11px 0 6px;color:#253d98;font-size:10px;font-weight:500;white-space:nowrap}.step-check span{width:22px;height:22px;display:grid;place-items:center;border-radius:50%;background:#eef0f8;font-size:10px}.step-check:hover{border-color:#253d98;background:#f7f8fc}.step-check:focus-visible{outline:2px solid #253d98;outline-offset:2px}.done .step-check{border-color:#39904a;background:#39904a;color:#fff}.done .step-check span{background:#fff;color:#287a38}.guide-steps h4{margin:0 0 4px}.guide-steps p{margin:0;color:#536075;font-size:11px}.flow li{align-items:start}.flow .badge{margin-bottom:6px}.flow p+p{margin-top:5px}.flow .review-question{margin-top:8px;color:#253d98}.review-question-grid,.scenario-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:9px}.review-question-grid p,.scenario-grid article{margin:0;border-radius:10px;background:#f8f9fb;padding:13px}.scenario-grid h4{margin:0 0 5px}.scenario-grid p{margin:0;color:#536075;font-size:11px}.review-checklist{display:grid;grid-template-columns:repeat(2,1fr);gap:22px}.review-checklist h4{margin:0 0 10px}.review-checklist label{display:flex;gap:8px;align-items:flex-start;margin:7px 0;color:#536075;font-size:11px}.feedback-grid{display:grid;grid-template-columns:1fr 280px;gap:22px}.feedback-grid pre{margin:0;border-radius:12px;background:#f3f5f9;padding:16px;white-space:pre-wrap;font:12px/1.6 "Fira Sans",sans-serif}.feedback-grid p{min-height:18px;color:#39904a;font-size:11px}.feedback-grid small{color:#657286}@media(max-width:1000px){.role-grid{grid-template-columns:repeat(2,1fr)}.process-map{grid-template-columns:1fr;gap:22px}.process-map li{min-height:0}.process-map li:not(:last-child):after{content:'↓';right:auto;left:50%;top:auto;bottom:-22px;transform:translateX(-50%)}.review-split{grid-template-columns:1fr}.guide-steps li{grid-template-columns:118px 1fr}.guide-steps li>.secondary{grid-column:2;justify-self:start}}@media(max-width:700px){.review-hero{grid-template-columns:1fr;padding:22px}.review-progress{justify-self:start}.role-grid,.review-question-grid,.scenario-grid,.review-checklist,.feedback-grid{grid-template-columns:1fr}.review-warning{display:block}.review-warning b{display:block;margin-bottom:5px}.guide-steps li{grid-template-columns:1fr}.guide-steps li>.secondary{grid-column:1}.step-check{justify-self:start}}
.process-map li+li{margin-top:0}@media(max-width:1180px){.process-map{grid-template-columns:1fr;gap:22px}.process-map li{min-height:0}.process-map li:not(:last-child):after{content:'↓';right:auto;left:50%;top:auto;bottom:-22px;transform:translateX(-50%)}}
.review-resume{display:flex;align-items:center;justify-content:space-between;gap:16px;border-color:#cfd6eb;padding:13px 18px}.review-resume div{display:grid;gap:2px}.review-resume span,.review-resume small{color:#657286;font-size:10px}.review-resume b{font-size:12px}
.feedback-layout{display:grid;grid-template-columns:minmax(300px,.8fr) minmax(0,1.2fr);gap:24px}.feedback-form label{display:grid;gap:8px;color:#536075;font-size:11px}.feedback-form textarea{width:100%;min-height:180px;border:1px solid #dfe4ed;border-radius:12px;background:#f3f5f9;padding:14px;color:#142033;font:12px/1.55 "Fira Sans",sans-serif;resize:vertical}.feedback-form textarea:focus-visible{border-color:#253d98;outline:2px solid rgba(37,61,152,.18);outline-offset:1px}.feedback-actions{display:flex;align-items:center;gap:12px;margin-top:12px}.feedback-actions span{color:#39904a;font-size:11px}.feedback-list h4{margin:0 0 10px}.feedback-list article{border-top:1px solid #e9edf2;padding:12px 0}.feedback-list article header{display:flex;justify-content:space-between;gap:12px;font-size:11px}.feedback-list article time{color:#8a96a7}.feedback-list article>p{margin:6px 0;white-space:pre-wrap;font-size:12px}.feedback-empty{color:#8a96a7;font-size:11px}.feedback-tags{display:flex;gap:5px;flex-wrap:wrap}.feedback-tags span{border-radius:12px;background:#eef0f8;padding:4px 8px;color:#253d98;font-size:9px}@media(max-width:700px){.feedback-layout{grid-template-columns:1fr}}
</style>
