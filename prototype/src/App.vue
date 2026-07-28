<script setup>
import { computed, ref } from 'vue'

const activeTab = ref('active')
const query = ref('')
const statusFilter = ref('')
const selected = ref(null)
const showCreate = ref(false)
const showHistory = ref(false)

const tabs = [
  { id: 'active', label: 'Активные заявки', count: 18 },
  { id: 'all', label: 'Все заявки', count: 146 },
  { id: 'mine', label: 'Мои заявки', count: 7 },
]

const requests = ref([
  { id: '000146', date: '27.07.2026', initiator: 'Максим Умнов', department: 'Бюро приводной техники', product: 'Лебёдка Furder VT40K', supplier: 'ООО «Вектор Технологий»', executor: 'С. И. Кашин', status: 'Заявка зарегистрирована', tone: 'blue' },
  { id: '000145', date: '27.07.2026', initiator: 'Виктор Медведев', department: 'Отдел производственных закупок', product: 'IP-видеокамера DS-2CD2543G2-IS', supplier: 'ООО «Видеотехнология»', executor: 'С. В. Наумов', status: 'Заявка в работе', tone: 'cyan' },
  { id: '000144', date: '24.07.2026', initiator: 'Андрей Соколов', department: 'Отдел главного конструктора', product: 'Ограничитель скорости ОС-2', supplier: 'АО «Лифткомплект»', executor: 'С. В. Прикуль', status: 'Работы приостановлены', tone: 'orange' },
  { id: '000143', date: '22.07.2026', initiator: 'Елена Орлова', department: 'Служба закупок', product: 'Частотный преобразователь 15 кВт', supplier: 'ООО «Электропривод»', executor: 'С. Д. Шапошников', status: 'Подготовка заключения', tone: 'violet' },
  { id: '000142', date: '21.07.2026', initiator: 'Павел Зимин', department: 'Управление качества', product: 'Буфер полиуретановый БП-100', supplier: 'ООО «Полимер»', executor: 'В. Я. Галкин', status: 'Контроль СБ', tone: 'yellow' },
  { id: '000141', date: '18.07.2026', initiator: 'Ирина Белова', department: 'Служба закупок', product: 'Датчик положения кабины', supplier: 'ООО «Сенсорика»', executor: 'В. В. Козлов', status: 'Заявка выполнена', tone: 'green' },
])

const statuses = [...new Set(requests.value.map(item => item.status))]
const filtered = computed(() => requests.value.filter(item => {
  const activeStatuses = ['Заявка зарегистрирована', 'Заявка в работе', 'Работы приостановлены', 'Подготовка заключения', 'Контроль СБ']
  if (activeTab.value === 'active' && !activeStatuses.includes(item.status)) return false
  if (activeTab.value === 'mine' && item.initiator !== 'Максим Умнов') return false
  if (statusFilter.value && item.status !== statusFilter.value) return false
  const haystack = Object.values(item).join(' ').toLowerCase()
  return haystack.includes(query.value.toLowerCase())
}))

function openRequest(item) {
  selected.value = item
  showHistory.value = false
}
</script>

<template>
  <div class="shell">
    <main>
      <header class="topbar">
        <div class="topbar-inner">
          <div class="brand-block">
            <span class="brand-mark">Щ</span>
            <div>
              <p class="eyebrow">АО «ЩЛЗ» · Испытательный центр</p>
              <h1>{{ selected ? `Заявка ${selected.id}` : 'Регистратор испытаний' }}</h1>
            </div>
          </div>
          <nav class="topnav">
            <button class="active">Заявки</button>
            <button>Справочники</button>
          </nav>
          <div class="profile">
            <span class="avatar">МУ</span>
            <span><b>Максим Умнов</b><small>Бюро приводной техники</small></span>
          </div>
        </div>
      </header>

      <section v-if="!selected" class="page">
        <div class="page-actions">
          <div>
            <h2>Заявки на проведение испытаний</h2>
            <p>Регистрация, испытания и согласование результатов</p>
          </div>
          <button class="primary" @click="showCreate = true">＋ Новая заявка</button>
        </div>

        <div class="card registry">
          <div class="tabs">
            <button v-for="tab in tabs" :key="tab.id" :class="{active: activeTab === tab.id}" @click="activeTab = tab.id">
              {{ tab.label }} <span>{{ tab.count }}</span>
            </button>
          </div>
          <div class="toolbar">
            <label class="search">⌕ <input v-model="query" placeholder="Поиск по заявкам" /></label>
            <select v-model="statusFilter"><option value="">Все статусы</option><option v-for="status in statuses" :key="status">{{ status }}</option></select>
            <button class="secondary">☷ Настроить таблицу</button>
          </div>
          <div class="table-wrap">
            <table>
              <thead><tr><th>№ заявки</th><th>Дата</th><th>Объект испытаний</th><th>Инициатор</th><th>Исполнитель</th><th>Статус</th><th></th></tr></thead>
              <tbody>
                <tr v-for="item in filtered" :key="item.id" @click="openRequest(item)">
                  <td class="number">{{ item.id }}</td><td>{{ item.date }}</td>
                  <td><b>{{ item.product }}</b><small>{{ item.supplier }}</small></td>
                  <td>{{ item.initiator }}<small>{{ item.department }}</small></td>
                  <td>{{ item.executor }}</td><td><span class="badge" :class="item.tone">{{ item.status }}</span></td><td>›</td>
                </tr>
              </tbody>
            </table>
            <div v-if="!filtered.length" class="empty"><div>⌕</div><h3>Ничего не найдено</h3><p>Измените запрос или очистите фильтры</p></div>
          </div>
          <footer class="pagination"><span>1–{{ filtered.length }} из {{ filtered.length }}</span><span><button>‹</button><button class="current">1</button><button>2</button><button>3</button><button>›</button></span></footer>
        </div>
      </section>

      <section v-else class="page request-page">
        <div class="request-actions">
          <button class="back" @click="selected = null">‹</button>
          <span class="badge" :class="selected.tone">{{ selected.status }}</span>
          <button class="secondary" @click="showHistory = true">◷ История</button>
          <button class="primary">Действия ▾</button>
        </div>
        <div class="request-grid">
          <div class="stack">
            <article class="card details">
              <h3>Общая информация</h3>
              <dl>
                <div><dt>Инициатор</dt><dd>{{ selected.initiator }}</dd></div><div><dt>Подразделение</dt><dd>{{ selected.department }}</dd></div>
                <div><dt>Наименование и тип</dt><dd>{{ selected.product }}</dd></div><div><dt>Производитель</dt><dd>Zhejiang Furder Drive Technology</dd></div>
                <div><dt>Поставщик</dt><dd>{{ selected.supplier }}</dd></div><div><dt>Количество образцов</dt><dd>1 шт.</dd></div>
                <div class="wide"><dt>Метод испытаний</dt><dd>Типовая программа испытаний, ресурсные испытания — 440 часов</dd></div>
              </dl>
            </article>
            <article class="card comments">
              <div class="section-title"><h3>Обсуждение <span>3</span></h3><button>⌕</button></div>
              <div class="comment"><span class="avatar small">МУ</span><div><b>Максим Умнов</b><time>Сегодня, 10:14</time><p>Образцы переданы в испытательный центр. Прикладываю сопроводительную документацию.</p><div class="file">PDF <span>Программа испытаний.pdf<small>2,4 МБ</small></span></div></div></div>
              <div class="comment"><span class="avatar small blue-avatar">СК</span><div><b>Сергей Кашин</b><time>Сегодня, 11:02</time><p>Заявка принята в работу. Ожидаемый срок завершения — 12 августа.</p></div></div>
              <label class="comment-input"><span class="avatar small">МУ</span><input placeholder="Оставьте комментарий…" /><button>➤</button></label>
            </article>
          </div>
          <aside class="stack side-column">
            <article class="card summary"><h3>Исполнение</h3><p><span>Исполнитель</span><b>{{ selected.executor }}</b></p><p><span>Эксперт</span><b>А. В. Смирнов</b></p><p><span>Отметка СБ</span><b>—</b></p></article>
            <article class="card"><h3>Документы</h3><div class="file large">DOCX <span>Сопроводительная документация<small>1,8 МБ</small></span></div><button class="upload">＋ Добавить файл</button></article>
            <article class="card timeline"><h3>Этапы</h3><p class="done">Заявка зарегистрирована<small>27 июля, 13:32</small></p><p class="active-step">Заявка в работе<small>Исполнитель назначен</small></p><p>Подготовка заключения</p><p>Контроль СБ</p><p>Завершение</p></article>
          </aside>
        </div>
      </section>
    </main>

    <div v-if="showCreate" class="overlay" @click.self="showCreate = false">
      <form class="modal" @submit.prevent="showCreate = false">
        <div class="modal-head"><div><p class="eyebrow">Новая заявка</p><h2>Проведение испытаний</h2></div><button type="button" @click="showCreate = false">×</button></div>
        <div class="form-grid">
          <label>Наименование и тип *<input required placeholder="Введите наименование продукции" /></label>
          <label>Количество образцов *<input required type="number" value="1" /></label>
          <label>Производитель *<input required placeholder="Наименование производителя" /></label>
          <label>Поставщик *<input required placeholder="Наименование поставщика" /></label>
          <label class="wide">Метод испытаний *<textarea required placeholder="Опишите метод или программу испытаний"></textarea></label>
          <label class="wide">Сопроводительная документация<div class="dropzone">Перетащите файлы сюда или <b>выберите на компьютере</b></div></label>
          <label class="wide">Комментарий<textarea placeholder="Дополнительная информация"></textarea></label>
        </div>
        <div class="modal-actions"><button type="button" class="secondary" @click="showCreate = false">Отмена</button><button class="primary">Создать заявку</button></div>
      </form>
    </div>

    <div v-if="showHistory" class="overlay drawer-overlay" @click.self="showHistory = false">
      <aside class="drawer"><div class="modal-head"><h2>История изменений</h2><button @click="showHistory = false">×</button></div>
        <div class="history"><div><b>Сергей Кашин</b><p>принял заявку в работу</p><time>27.07.2026, 14:08</time></div><div><b>Руководитель ИЦ</b><p>назначил исполнителя: Сергей Кашин</p><time>27.07.2026, 13:48</time></div><div><b>Максим Умнов</b><p>создал заявку</p><time>27.07.2026, 13:32</time></div></div>
      </aside>
    </div>
  </div>
</template>
