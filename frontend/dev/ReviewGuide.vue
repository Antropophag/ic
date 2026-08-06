<script setup>
import { computed, ref } from 'vue'
import { readReviewGuideProgress, writeReviewGuideProgress } from './review-guide'

const emit = defineEmits(['navigate', 'seed-demo'])
const saved = readReviewGuideProgress()
const completed = ref(new Set(saved.completed))
const context = ref(saved.context)
const copyState = ref('')

const quickSteps = [
  ['quick-seed', 'Заполните демоданные', 'Переключитесь на Дарью Королёву — администратора — и нажмите «Заполнить демо».', 'seed'],
  ['quick-registry', 'Посмотрите рабочий стол', 'Оцените персональные очереди, вкладки, поиск, статусы и понятность приоритетов.', 'registry'],
  ['quick-card', 'Откройте показательную заявку', 'Найдите «демо-серия 005»: в ней есть документы, история и ожидается решение СБ.', 'registry'],
  ['quick-details', 'Изучите карточку', 'Проверьте статус, участников, доступные действия, документы и ленту.', 'registry'],
  ['quick-role', 'Смените пользователя', 'Выберите Олега Воронцова — сотрудника СБ. Страница перезагрузится.', 'registry'],
  ['quick-actions', 'Сравните права', 'Снова найдите серию 005 и убедитесь, что появились действия СБ.', 'registry'],
  ['quick-safe', 'Выполните безопасное действие', 'Добавьте комментарий или скачайте доступный документ. Не завершайте заявку, если её смотрят коллеги.', 'registry'],
  ['quick-impression', 'Зафиксируйте впечатление', 'Запишите, что было непонятно без подсказки и где пришлось искать следующий шаг.', 'none'],
]

const flowSteps = [
  ['flow-1', 'Сотрудник', 'Создание и регистрация', 'Откройте «Новая заявка», проверьте обязательные поля и сохранение черновика. Для короткого пути найдите серию 001.', 'Заявка зарегистрирована', 'Понятно ли, что нужно заполнить и что произойдёт после отправки?', '001'],
  ['flow-2', 'Руководитель ИЦ', 'Назначение исполнителя', 'Под Максимом Умновым откройте серию 001, выберите исполнителя и подтвердите назначение.', 'Заявка зарегистрирована', 'Легко ли выбрать ответственного и заметен ли результат?', '001'],
  ['flow-3', 'Исполнитель ИЦ', 'Начало и проведение работ', 'Под Сергеем Кашиным откройте назначенную заявку и начните работу. Для готового этапа используйте серию 002.', 'Заявка в работе', 'Ясно ли, что доступно только назначенному исполнителю?', '002'],
  ['flow-4', 'Исполнитель ИЦ', 'Загрузка отчёта', 'В серии 002 загрузите PDF до 10 МБ либо изучите готовый отчёт в серии 004.', 'Подготовка заключения', 'Понятны ли формат файла, эффект загрузки и доступ к версиям?', '004'],
  ['flow-5', 'Эксперт', 'Подготовка заключения', 'Под Анной Смирновой найдите серию 004, возьмите её в работу и опубликуйте заключение.', 'Контроль СБ', 'Хватает ли сведений для решения и ясно ли, что PDF создастся автоматически?', '004'],
  ['flow-6', 'Сотрудник СБ', 'Контроль заключения', 'Под Олегом Воронцовым откройте серию 005 и выберите: согласовать или вернуть с причиной.', 'Заявка выполнена или Подготовка заключения', 'Понятны ли последствия обоих решений и обязательность причины возврата?', '005'],
]

const roles = [
  ['Сотрудник', 'Создаёт заявки и следит за своими обращениями.', 'Реестр, свои заявки, карточки и общие вложения.', 'Создать заявку, добавить комментарий, отозвать незавершённую заявку.', 'Начните с серии 001; проверьте форму и вкладку «Мои заявки».'],
  ['Руководитель ИЦ', 'Организует работу испытательного центра.', 'Все заявки, персональные очереди назначения и работы.', 'Назначить или сменить исполнителя, начать/приостановить работу, отказать, поставить цветовую метку.', 'Начните с серий 001–003; проверьте недоступность решения СБ.'],
  ['Руководитель лаборатории', 'Выполняет те же процессные действия руководителя на заявках.', 'Реестр, карточки и документы процесса.', 'Назначать исполнителя, управлять ходом работ, загружать отчёт, отказать.', 'Сравните с руководителем ИЦ на сериях 001–003.'],
  ['Исполнитель ИЦ', 'Проводит испытания и готовит отчёт.', 'Назначенные заявки и свою очередь действий.', 'Начать, приостановить, возобновить работу; загрузить, заменить или удалить отчёт.', 'Начните с серий 002 и 003; проверьте, что чужие заявки нельзя вести.'],
  ['Эксперт', 'Проверяет отчёт и выпускает экспертное заключение.', 'Заявки на экспертизу и отчёт после взятия заявки в работу.', 'Взять или перехватить заявку, передать коллеге, опубликовать заключение.', 'Начните с серии 004; сравните карточку до и после взятия в работу.'],
  ['Сотрудник СБ', 'Принимает итоговое решение по заключению.', 'Очередь контроля, заключение и историю процесса.', 'Согласовать и завершить либо вернуть с обязательной причиной.', 'Начните с серии 005; проверьте оба варианта после нового сброса демо.'],
  ['Администратор', 'Управляет доступом и помогает разбирать события.', 'Пользователей и роли, аудит, журнал уведомлений.', 'Назначать роли, исправлять подразделение заявки, читать журналы; в dev — сбрасывать демо.', 'Проверьте администрирование и фильтры журналов, не меняя роли коллег без необходимости.'],
]

const extraScenarios = [
  ['Приостановка и возврат к работе', 'Серия 003: исполнитель или руководитель возобновляет работу; на серии 002 можно проверить приостановку.'],
  ['Возврат СБ и повторный цикл', 'Серия 004 уже содержит возврат. Серия 005 позволяет вернуть заявку с новой причиной.'],
  ['Отказ и отзыв', 'Серии 007 и 008 показывают завершённые ветки и причины в ленте.'],
  ['Комментарии и вложения', 'На активной заявке добавьте комментарий и файл допустимого типа; после финального решения комментарий должен быть недоступен.'],
  ['Версии и удаление отчёта', 'Исполнитель или руководитель может заменить и удалить отчёт. После удаления повторная загрузка запускает цикл экспертизы заново.'],
  ['Пустые и запрещённые состояния', 'Используйте фильтр без результатов и откройте одну заявку под непричастным сотрудником: лишние действия и закрытые документы не должны появиться.'],
]

const reviewGroups = [
  ['Логика процесса', ['Порядок действий совпадает с ожиданиями?', 'Понятно, кто отвечает за следующий шаг?', 'Статус меняется сразу и предсказуемо?', 'Возврат и повторный цикл сохраняют контекст?']],
  ['Роли и права', ['В очереди только задачи этого пользователя?', 'Нужные действия доступны, а запрещённые скрыты или объяснены?', 'Различие ролей заметно без знания регламента?', 'Нельзя выполнить действие не на своём этапе?']],
  ['Интерфейс и тексты', ['Легко найти заявку, документы и историю?', 'Названия статусов и кнопок понятны?', 'После действия виден результат и следующий шаг?', 'Ошибки объясняют, как исправить ситуацию?']],
  ['Ошибки', ['Нет зависаний, пустых экранов и ошибок загрузки?', 'Кнопки дают ожидаемый результат?', 'Данные и переходы не выглядят противоречиво?', 'Нет обрезанных текстов и визуальных дефектов?']],
]

const feedbackTemplate = `Роль:\nСтраница:\nЗаявка или демо-объект:\nЧто я делал:\nЧто ожидал:\nЧто произошло:\nПочему это мешает:\nСкриншот: приложен / не нужен`
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

function go(target, step, role = '', object = '') {
  const context = { scenario: target.startsWith('quick') ? 'Быстрый обзор' : 'Полный сценарий', step, role, object }
  persist(context)
  if (target === 'seed') emit('seed-demo')
  else emit('navigate', context)
}

function reset() {
  completed.value = new Set()
  context.value = null
  writeReviewGuideProgress({ completed: [], context: null })
}

async function copyFeedback() {
  try {
    await navigator.clipboard.writeText(feedbackTemplate)
    copyState.value = 'Шаблон скопирован'
  } catch {
    copyState.value = 'Не удалось скопировать — выделите текст вручную'
  }
}
</script>

<template>
  <div class="page review-guide">
    <section class="review-hero card">
      <div><p class="eyebrow">Инструмент предварительного ревью · dev</p><h2>Разберитесь в процессе на реальных экранах</h2><p>Это портал заявок на испытания. Пройдите путь от регистрации до решения СБ и отмечайте всё, что мешает понять задачу, ответственность или следующий шаг.</p><div class="review-time"><span>Быстрый обзор · около 10 минут</span><span>Полный сценарий · 25–40 минут</span></div></div>
      <div class="review-progress" :style="{ '--progress': `${progress}%` }"><strong>{{ progress }}%</strong><span>{{ doneCount }} из {{ totalSteps }} шагов</span><button class="review-link" type="button" @click="reset">Сбросить прогресс</button></div>
    </section>

    <aside class="review-warning"><b>Это dev-билд</b><span>Данные демонстрационные — их можно менять. Интеграции и реальные уведомления могут быть отключены или заменены имитацией. Dev-инструменты не попадут в production. Фиксируйте любые помехи: статус разработки не делает дефект менее важным.</span></aside>

    <aside v-if="context" class="review-resume card"><div><span>Вы вернулись из портала</span><b>{{ context.scenario }} · {{ context.step }}</b><small v-if="context.role">Нужная роль: {{ context.role }}<template v-if="context.object"> · объект: {{ context.object }}</template></small></div><button class="review-link" type="button" @click="persist(null)">Закрыть</button></aside>

    <nav class="review-nav card" aria-label="Разделы гайда"><a href="#prepare">Подготовка</a><a href="#roles">Роли</a><a href="#process">Процесс</a><a href="#quick">10 минут</a><a href="#full">Полный путь</a><a href="#checklist">Чек-лист</a><a href="#feedback">Замечание</a></nav>

    <details id="prepare" class="review-section card" open><summary><span>01</span><div><h3>Подготовьте демонстрационные данные</h3><p>Начните с одинакового, предсказуемого реестра.</p></div></summary><div class="review-section-body review-split"><div><p>Выберите в переключателе пользователя <b>Дарью Королёву</b>. Только администратор видит кнопку «Заполнить демо».</p><ul><li>Создаётся 100 синтетических заявок во всех реализованных статусах, с комментариями и файлами.</li><li>Повторный запуск допустим, но каждый раз удаляет все текущие заявки, комментарии, историю и файлы.</li><li>Пользователи и их роли не меняются.</li><li>Изменения других проверяющих восстановить отдельно нельзя. Если набор уже изменён — согласуйте сброс или работайте с текущими данными.</li></ul></div><div class="review-callout"><b>Общий сброс необратим</b><p>Перед запуском убедитесь, что коллега не проходит сценарий в том же dev-реестре.</p><button class="primary" type="button" @click="go('seed', 'Подготовка демоданных', 'Администратор')">Открыть заполнение демо</button></div></div></details>

    <details id="roles" class="review-section card" open><summary><span>02</span><div><h3>Переключайтесь между участниками</h3><p>Переключатель в шапке меняет пользователя, права и персональные очереди, затем перезагружает страницу.</p></div></summary><div class="review-section-body"><p class="review-lead">Один объект важно открыть под разными ролями: так видны реальные границы доступа. Гайд не переключает пользователя скрытно — выберите его сами в шапке.</p><div class="role-grid"><article v-for="role in roles" :key="role[0]" class="role-card"><span class="role-dot"></span><h4>{{ role[0] }}</h4><b>{{ role[1] }}</b><p>{{ role[2] }}</p><p><strong>Действия:</strong> {{ role[3] }}</p><small>{{ role[4] }}</small></article></div></div></details>

    <details id="process" class="review-section card" open><summary><span>03</span><div><h3>Карта основного процесса</h3><p>Нормальный путь и отдельные возвратные ветки.</p></div></summary><div class="review-section-body"><ol class="process-map"><li><i>1</i><b>Сотрудник</b><span>Создаёт заявку</span><em><small>Статус</small>Зарегистрирована</em></li><li><i>2</i><b>Руководитель</b><span>Назначает исполнителя</span><em><small>Статус</small>Зарегистрирована</em></li><li><i>3</i><b>Исполнитель</b><span>Начинает работу и загружает отчёт</span><em><small>Статус</small>Подготовка заключения</em></li><li><i>4</i><b>Эксперт</b><span>Берёт заявку и публикует заключение</span><em><small>Статус</small>Контроль СБ</em></li><li><i>5</i><b>Сотрудник СБ</b><span>Согласует заключение</span><em><small>Статус</small>Выполнена</em></li></ol><div class="process-branches"><b>Ветвления процесса</b><span>Исполнитель ↔ приостановка и возобновление</span><span>СБ → возврат эксперту с причиной → новое заключение</span><span>Руководитель → отказ</span><span>Инициатор → отзыв до решения СБ</span></div></div></details>

    <details id="quick" class="review-section card" open><summary><span>04</span><div><h3>Быстрый обзор · 10 минут</h3><p>Минимальный маршрут, чтобы составить первое мнение. После проверки нажмите «Отметить шаг».</p></div></summary><div class="review-section-body"><ol class="guide-steps"><li v-for="(step, index) in quickSteps" :key="step[0]" :class="{ done: completed.has(step[0]) }"><button class="step-check" type="button" :aria-pressed="completed.has(step[0])" @click="toggle(step[0])"><span>{{ completed.has(step[0]) ? '✓' : index + 1 }}</span>{{ completed.has(step[0]) ? 'Выполнено' : 'Отметить шаг' }}</button><div><h4>{{ step[1] }}</h4><p>{{ step[2] }}</p></div><button v-if="step[3] !== 'none'" class="secondary" type="button" @click="go(step[3], step[1])">{{ step[3] === 'seed' ? 'К демоданным' : 'Открыть портал' }}</button></li></ol></div></details>

    <details id="full" class="review-section card" open><summary><span>05</span><div><h3>Полный сквозной сценарий</h3><p>Двигайтесь по одной заявке или начинайте с готовой демо-серии. Каждый проверенный шаг отметьте отдельной кнопкой.</p></div></summary><div class="review-section-body"><ol class="guide-steps flow"><li v-for="(step, index) in flowSteps" :key="step[0]" :class="{ done: completed.has(step[0]) }"><button class="step-check" type="button" :aria-pressed="completed.has(step[0])" @click="toggle(step[0])"><span>{{ completed.has(step[0]) ? '✓' : index + 1 }}</span>{{ completed.has(step[0]) ? 'Выполнено' : 'Отметить шаг' }}</button><div><span class="badge blue">{{ step[1] }}</span><h4>{{ step[2] }}</h4><p><b>Действие:</b> {{ step[3] }}</p><p><b>Ожидаемый результат:</b> {{ step[4] }}</p><p class="review-question">Обратите внимание: {{ step[5] }}</p></div><button class="secondary" type="button" @click="go('flow', step[2], step[1], `демо-серия ${step[6]}`)">Найти серию {{ step[6] }}</button></li></ol></div></details>

    <details class="review-section card"><summary><span>06</span><div><h3>Что проверить по ролям</h3><p>Используйте карточки ролей выше как маршрут и задайте себе эти вопросы.</p></div></summary><div class="review-section-body"><div class="review-question-grid"><p v-for="question in ['Понятно ли, какие заявки требуют моего внимания?', 'Видно ли, кто и когда выполнил предыдущий шаг?', 'Понятно ли, почему действие недоступно?', 'Достаточно ли информации для принятия решения?', 'Легко ли найти нужные документы?', 'Можно ли ошибочно выполнить действие не на том этапе?', 'Понятно ли, что произойдёт после нажатия?', 'Отличается ли рабочий стол этой роли от остальных?']" :key="question">{{ question }}</p></div></div></details>

    <details class="review-section card"><summary><span>07</span><div><h3>Дополнительные сценарии</h3><p>Только ветки, которые уже можно пройти в dev-билде.</p></div></summary><div class="review-section-body scenario-grid"><article v-for="scenario in extraScenarios" :key="scenario[0]"><h4>{{ scenario[0] }}</h4><p>{{ scenario[1] }}</p><button class="review-link" type="button" @click="go('extra', scenario[0])">Открыть реестр →</button></article></div></details>

    <details id="checklist" class="review-section card" open><summary><span>08</span><div><h3>Чек-лист ревью</h3><p>Проверяйте не только успешный путь, но и понятность ограничений.</p></div></summary><div class="review-section-body review-checklist"><section v-for="group in reviewGroups" :key="group[0]"><h4>{{ group[0] }}</h4><label v-for="item in group[1]" :key="item"><input type="checkbox">{{ item }}</label></section></div></details>

    <details id="feedback" class="review-section card" open><summary><span>09</span><div><h3>Оставьте замечание</h3><p>Согласованный канал в проекте не указан — скопируйте шаблон в рабочий канал команды.</p></div></summary><div class="review-section-body feedback-grid"><pre>{{ feedbackTemplate }}</pre><div><button class="primary" type="button" @click="copyFeedback">Скопировать шаблон</button><p role="status">{{ copyState }}</p><small>Хорошее замечание позволяет повторить ситуацию: укажите роль, заявку и последовательность действий. Для визуальной проблемы приложите скриншот.</small></div></div></details>

    <details class="review-section card"><summary><span>10</span><div><h3>Пока не проверяем</h3><p>Ограничения текущего dev-билда.</p></div></summary><div class="review-section-body"><ul><li>Реальную доставку писем: SMTP может быть имитацией; в журнале проверяйте постановку и статус, а не получение адресатом.</li><li>Внешние интеграции и миграцию Bitrix24 как часть пользовательского маршрута.</li><li>Переключение на второго эксперта через шапку: профиль есть для данных и переназначения, но в обычном dev-переключателе его нет.</li><li>Одновременную изолированную работу нескольких проверяющих: демо-реестр общий, сброс затрагивает всех.</li><li>Автоматический переход к заявке по названию серии: откройте реестр и найдите её поиском вручную.</li></ul></div></details>
  </div>
</template>

<style scoped>
.review-guide{display:grid;gap:12px;padding-top:8px}.review-hero{display:grid;grid-template-columns:1fr 180px;gap:28px;padding:32px;background:linear-gradient(135deg,#fff 55%,#eef0f8)}.review-hero h2{max-width:760px;margin:5px 0 10px;font-size:30px;letter-spacing:-.02em}.review-hero p{max-width:760px;margin:0;color:#536075;line-height:1.55}.review-time{display:flex;gap:9px;margin-top:18px;flex-wrap:wrap}.review-time span,.process-branches span{border-radius:16px;background:#eef0f8;padding:7px 11px;color:#253d98;font-size:11px}.review-progress{width:154px;height:154px;align-self:center;justify-self:end;display:grid;place-content:center;border-radius:50%;background:radial-gradient(closest-side,#fff 78%,transparent 80% 99%),conic-gradient(#253d98 var(--progress),#dfe3eb 0);text-align:center}.review-progress strong{font-size:27px}.review-progress span{font-size:10px;color:#657286}.review-link{border:0;background:transparent;padding:5px;color:#253d98;font-size:11px}.review-warning{display:flex;gap:14px;align-items:flex-start;border:1px solid #f1d2aa;border-radius:12px;background:#fff8ed;padding:14px 18px;color:#6e4b1f;font-size:12px;line-height:18px}.review-warning b{white-space:nowrap}.review-nav{position:sticky;top:8px;z-index:5;display:flex;gap:4px;padding:7px;box-shadow:0 6px 24px rgba(11,22,35,.07);overflow:auto}.review-nav a{border-radius:17px;padding:8px 11px;color:#536075;font-size:11px;text-decoration:none;white-space:nowrap}.review-nav a:hover{background:#eef0f8;color:#253d98}.review-section{scroll-margin-top:60px;overflow:hidden}.review-section summary{display:flex;gap:14px;align-items:center;padding:18px 22px;cursor:pointer;list-style:none}.review-section summary::-webkit-details-marker{display:none}.review-section summary>span{width:34px;height:34px;display:grid;place-items:center;flex:none;border-radius:50%;background:#eef0f8;color:#253d98;font-size:11px;font-weight:600}.review-section summary h3{margin:0;font-size:16px}.review-section summary p{margin:3px 0 0;color:#8a96a7;font-size:11px}.review-section summary:after{content:'+';margin-left:auto;color:#657286;font-size:20px}.review-section[open] summary:after{content:'−'}.review-section-body{border-top:1px solid #e9edf2;padding:22px;line-height:1.55;font-size:13px}.review-section-body>p:first-child{margin-top:0}.review-section-body li+li{margin-top:7px}.review-split{display:grid;grid-template-columns:1fr 340px;gap:28px}.review-callout{align-self:start;border-radius:12px;background:#f8f9fb;padding:18px}.review-callout p{color:#657286}.review-lead{color:#536075}.role-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:10px}.role-card{position:relative;border:1px solid #e9edf2;border-radius:12px;padding:16px}.role-card h4{margin:0 0 7px}.role-card>b{font-size:11px}.role-card p{margin:8px 0;color:#536075;font-size:11px}.role-card small{display:block;border-top:1px solid #e9edf2;padding-top:9px;color:#253d98}.role-dot{position:absolute;right:14px;top:14px;width:8px;height:8px;border-radius:50%;background:#253d98}.process-map{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:28px;margin:0;padding:0;list-style:none}.process-map li{position:relative;min-height:154px;display:flex;flex-direction:column;border:1px solid #dfe4ed;border-radius:12px;background:#f8f9fb;padding:14px}.process-map li:not(:last-child):after{content:'→';position:absolute;z-index:2;right:-24px;top:50%;width:20px;color:#253d98;font-size:20px;line-height:20px;text-align:center;transform:translateY(-50%)}.process-map i{width:24px;height:24px;display:grid;place-items:center;margin-bottom:10px;border-radius:50%;background:#253d98;color:#fff;font-size:10px;font-style:normal;font-weight:600}.process-map b,.process-map span,.process-map em{display:block}.process-map b{font-size:12px}.process-map span{margin:5px 0 12px;color:#536075;font-size:11px;line-height:16px}.process-map em{margin-top:auto;border-top:1px solid #e1e5ec;padding-top:9px;color:#253d98;font-size:10px;line-height:14px;font-style:normal}.process-map em small{display:block;margin-bottom:2px;color:#8a96a7;font-size:8px;letter-spacing:.05em;text-transform:uppercase}.process-branches{display:flex;gap:7px;align-items:center;flex-wrap:wrap;margin-top:18px}.process-branches>b{margin-right:3px;font-size:11px}.guide-steps{display:grid;gap:8px;margin:0;padding:0;list-style:none}.guide-steps li{display:grid;grid-template-columns:118px 1fr auto;gap:14px;align-items:center;border:1px solid #e9edf2;border-radius:12px;padding:13px}.guide-steps li.done{border-color:#bcd8c1;background:#f2f8f3}.step-check{height:34px;display:inline-flex;align-items:center;gap:7px;border:1px solid #bfc8d5;border-radius:17px;background:#fff;padding:0 11px 0 6px;color:#253d98;font-size:10px;font-weight:500;white-space:nowrap}.step-check span{width:22px;height:22px;display:grid;place-items:center;border-radius:50%;background:#eef0f8;font-size:10px}.step-check:hover{border-color:#253d98;background:#f7f8fc}.step-check:focus-visible{outline:2px solid #253d98;outline-offset:2px}.done .step-check{border-color:#39904a;background:#39904a;color:#fff}.done .step-check span{background:#fff;color:#287a38}.guide-steps h4{margin:0 0 4px}.guide-steps p{margin:0;color:#536075;font-size:11px}.flow li{align-items:start}.flow .badge{margin-bottom:6px}.flow p+p{margin-top:5px}.flow .review-question{margin-top:8px;color:#253d98}.review-question-grid,.scenario-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:9px}.review-question-grid p,.scenario-grid article{margin:0;border-radius:10px;background:#f8f9fb;padding:13px}.scenario-grid h4{margin:0 0 5px}.scenario-grid p{margin:0;color:#536075;font-size:11px}.review-checklist{display:grid;grid-template-columns:repeat(2,1fr);gap:22px}.review-checklist h4{margin:0 0 10px}.review-checklist label{display:flex;gap:8px;align-items:flex-start;margin:7px 0;color:#536075;font-size:11px}.feedback-grid{display:grid;grid-template-columns:1fr 280px;gap:22px}.feedback-grid pre{margin:0;border-radius:12px;background:#f3f5f9;padding:16px;white-space:pre-wrap;font:12px/1.6 "Fira Sans",sans-serif}.feedback-grid p{min-height:18px;color:#39904a;font-size:11px}.feedback-grid small{color:#657286}@media(max-width:1000px){.role-grid{grid-template-columns:repeat(2,1fr)}.process-map{grid-template-columns:1fr;gap:22px}.process-map li{min-height:0}.process-map li:not(:last-child):after{content:'↓';right:auto;left:50%;top:auto;bottom:-22px;transform:translateX(-50%)}.review-split{grid-template-columns:1fr}.guide-steps li{grid-template-columns:118px 1fr}.guide-steps li>.secondary{grid-column:2;justify-self:start}}@media(max-width:700px){.review-hero{grid-template-columns:1fr;padding:22px}.review-progress{justify-self:start}.role-grid,.review-question-grid,.scenario-grid,.review-checklist,.feedback-grid{grid-template-columns:1fr}.review-warning{display:block}.review-warning b{display:block;margin-bottom:5px}.guide-steps li{grid-template-columns:1fr}.guide-steps li>.secondary{grid-column:1}.step-check{justify-self:start}}
.process-map li+li{margin-top:0}@media(max-width:1180px){.process-map{grid-template-columns:1fr;gap:22px}.process-map li{min-height:0}.process-map li:not(:last-child):after{content:'↓';right:auto;left:50%;top:auto;bottom:-22px;transform:translateX(-50%)}}
.review-resume{display:flex;align-items:center;justify-content:space-between;gap:16px;border-color:#cfd6eb;padding:13px 18px}.review-resume div{display:grid;gap:2px}.review-resume span,.review-resume small{color:#657286;font-size:10px}.review-resume b{font-size:12px}
</style>
