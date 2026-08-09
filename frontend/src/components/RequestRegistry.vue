<script setup>
import {
  computed,
  nextTick,
  onBeforeUnmount,
  onMounted,
  reactive,
  ref,
  watch,
} from "vue";
import { requestApi } from "../api";
import { createApplicationDraftForm } from "../applicationDraftForm";
import { createConfirmDialog } from "../confirmDialog";
import { triggerBlobDownload } from "../download";
import { createRequestRegistryLoadLifecycle } from "../requestRegistryLoadLifecycle";
import {
  REGISTRY_PAGE_SIZES,
  readNotificationCursor,
  readRegistryPageSize,
  writeNotificationCursor,
  writeRegistryPageSize,
} from "../registryPreferences";
import AppIcon from "./AppIcon.vue";
import AppModal from "./AppModal.vue";
import HelpArticle from "./HelpArticle.vue";
import {
  REQUEST_STATUS_OPTIONS,
  avatarRoleClass,
  fromApi,
  initialsFor,
} from "../registry";

const props = defineProps({
  active: { type: Boolean, default: true },
  currentUserId: { type: Number, required: true },
  refreshTrigger: { type: Number, default: 0 },
});
const emit = defineEmits([
  "select-request",
]);
const ATTENTION_ICONS = Object.freeze({
  assign_executor: "user",
  start_or_resume_work: "play",
  upload_report: "upload",
  claim_expert: "file",
  publish_opinion: "file-check",
  security_decision: "shield-check",
});
const PAGE_SIZE_OPTIONS = REGISTRY_PAGE_SIZES;
const activeTab = ref("active");
const activeAttention = ref("");
const attentionCategories = ref([]);
const dashboardLoading = ref(true);
const dashboardError = ref("");
const showDashboardHelp = ref(false);
const dashboardHelpTrigger = ref(null);
const dashboardHelpDrawer = ref(null);
const query = ref("");
const statusFilter = ref("");
const sortDirection = ref("desc");
const currentPage = ref(1);
const pageSize = ref(readRegistryPageSize());
const notificationItems = ref([]);
const notificationsLoading = ref(true);
const notificationsError = ref("");
const showNotifications = ref(false);
const notificationCursor = ref(readNotificationCursor(props.currentUserId));
const requests = ref([]);
const registryPage = reactive({
  total: 0,
  page: 1,
  pageSize: pageSize.value,
  pageCount: 1,
  counts: { active: 0, all: 0, mine: 0 },
});
const registryError = ref("");
const registryLoading = ref(true);
const showCreate = ref(false);
const createLoading = ref(false);
const createError = ref("");
const createNotice = ref("");
const draftFiles = ref([]);
const draftFileInput = ref(null);
const lastCommentModal = ref(null);
const draft = reactive({
  productName: "",
  manufacturer: "",
  supplier: "",
  sampleQuantity: 1,
  testMethod: "",
  comment: "",
});
const registryLoadLifecycle = createRequestRegistryLoadLifecycle();
const {
  registryGuard,
  dashboardGuard,
  notificationGuard,
  downloadGuard,
  createRequestGuard,
} = registryLoadLifecycle;
const confirmDialog = createConfirmDialog();
const draftForm = createApplicationDraftForm({
  userId: props.currentUserId,
  draft,
  files: () => draftFiles.value,
  notify: message => { createNotice.value = message; },
});

function resetCreateForm({ removeStored = false } = {}) {
  if (removeStored) draftForm.remove();
  Object.assign(draft, {
    productName: "",
    manufacturer: "",
    supplier: "",
    sampleQuantity: 1,
    testMethod: "",
    comment: "",
  });
  draftFiles.value = [];
  if (draftFileInput.value) draftFileInput.value.value = "";
  createError.value = "";
  createNotice.value = "";
  draftForm.enableSaving();
}

function hasCreateFormData() {
  return draft.productName !== ""
    || draft.manufacturer !== ""
    || draft.supplier !== ""
    || draft.sampleQuantity !== 1
    || draft.testMethod !== ""
    || draft.comment !== ""
    || draftFiles.value.length > 0;
}

async function clearCreateDraft() {
  if (hasCreateFormData() && !(await confirmDialog.ask(
    "Очистить форму и удалить сохранённый черновик?",
    { confirmLabel: "Очистить" },
  ))) return;
  resetCreateForm({ removeStored: true });
}

const tabs = computed(() => [
  { id: "active", label: "Активные заявки", count: registryPage.counts.active },
  { id: "all", label: "Все заявки", count: registryPage.counts.all },
  { id: "mine", label: "Мои заявки", count: registryPage.counts.mine },
]);
const paged = computed(() => ({ items: requests.value, ...registryPage }));
const newNotifications = computed(() => notificationItems.value.filter(item => !notificationCursor.value || item.occurredAt > notificationCursor.value));
const pageNumbers = computed(() => {
  const visiblePages = Math.min(7, paged.value.pageCount);
  const firstPage = Math.max(
    1,
    Math.min(
      paged.value.page - Math.floor(visiblePages / 2),
      paged.value.pageCount - visiblePages + 1,
    ),
  );
  return Array.from({ length: visiblePages }, (_, index) => firstPage + index);
});

async function loadRequests({ rethrow = false } = {}) {
  const token = registryGuard.begin(true);
  registryLoading.value = true;
  try {
    const result = await requestApi.list({
      page: currentPage.value,
      pageSize: pageSize.value,
      tab: activeTab.value,
      status: statusFilter.value,
      query: query.value.trim(),
      sort: sortDirection.value,
      attention: activeAttention.value,
    });
    if (!registryGuard.isCurrent(token, true)) return;
    registryError.value = "";
    requests.value = result.items.map(fromApi);
    Object.assign(registryPage, {
      total: result.total ?? result.items.length,
      page: result.page ?? 1,
      pageSize: result.pageSize ?? pageSize.value,
      pageCount: result.pageCount ?? 1,
      counts: result.counts ?? {
        active: result.items.length,
        all: result.items.length,
        mine: 0,
      },
    });
    currentPage.value = registryPage.page;
  } catch (error) {
    if (rethrow) throw error;
    if (registryGuard.isCurrent(token, true))
      registryError.value =
        "Не удалось загрузить реестр заявок. Повторите попытку.";
  } finally {
    if (registryGuard.isCurrent(token, true)) registryLoading.value = false;
  }
}

async function loadDashboard() {
  const token = dashboardGuard.begin(true);
  dashboardLoading.value = true;
  try {
    const result = await requestApi.dashboard();
    if (!dashboardGuard.isCurrent(token, true)) return;
    dashboardError.value = "";
    attentionCategories.value = result.categories || [];
    if (activeAttention.value && !attentionCategories.value.some(category => category.id === activeAttention.value)) {
      activeAttention.value = "";
    }
  } catch {
    if (dashboardGuard.isCurrent(token, true)) {
      dashboardError.value = "Не удалось обновить список текущих задач.";
    }
  } finally {
    if (dashboardGuard.isCurrent(token, true)) dashboardLoading.value = false;
  }
}

async function loadNotifications() {
  const token = notificationGuard.begin(true);
  notificationsLoading.value = true;
  try {
    const result = await requestApi.events();
    if (!notificationGuard.isCurrent(token, props.active)) return;
    notificationItems.value = result.items || [];
    notificationsError.value = "";
    if (showNotifications.value) {
      await nextTick();
      if (notificationGuard.isCurrent(token, props.active) && showNotifications.value) markNotificationsViewed();
    }
  } catch {
    if (!notificationGuard.isCurrent(token, props.active)) return;
    notificationsError.value = "Не удалось загрузить новые события.";
  } finally {
    if (notificationGuard.isCurrent(token, props.active)) notificationsLoading.value = false;
  }
}

function markNotificationsViewed() {
  const newest = notificationItems.value[0]?.occurredAt;
  if (!notificationsError.value && newest) {
    notificationCursor.value = newest;
    writeNotificationCursor(props.currentUserId, newest);
  }
}

async function openNotifications() {
  showNotifications.value = true;
  if (!notificationItems.value.length || notificationsError.value) await loadNotifications();
  await nextTick();
  if (showNotifications.value && !notificationsLoading.value) markNotificationsViewed();
}

function closeNotifications() {
  showNotifications.value = false;
  notificationsLoading.value = false;
  notificationGuard.invalidate();
}

function selectNotification(item) {
  closeNotifications();
  emit('select-request', { backendId: Number(item.requestId), id: item.requestNumber });
}

function selectAttention(categoryId) {
  activeAttention.value = activeAttention.value === categoryId ? "" : categoryId;
  activeTab.value = "all";
}

function attentionIcon(categoryId) {
  return ATTENTION_ICONS[categoryId] || "file";
}

function openDashboardHelp() {
  showDashboardHelp.value = true;
  nextTick(() => dashboardHelpDrawer.value?.querySelector("button")?.focus());
}

function closeDashboardHelp({ restoreFocus = true } = {}) {
  showDashboardHelp.value = false;
  if (restoreFocus) nextTick(() => dashboardHelpTrigger.value?.focus());
}

function handleDashboardHelpKeydown(event) {
  if (event.key === "Escape") {
    event.preventDefault();
    closeDashboardHelp();
    return;
  }
  if (event.key !== "Tab" || !dashboardHelpDrawer.value) return;
  const focusable = [...dashboardHelpDrawer.value.querySelectorAll('button,[href],[tabindex]:not([tabindex="-1"])')]
    .filter((element) => !element.disabled);
  if (!focusable.length) return;
  const first = focusable[0];
  const last = focusable.at(-1);
  if (event.shiftKey && document.activeElement === first) {
    event.preventDefault();
    last.focus();
  } else if (!event.shiftKey && document.activeElement === last) {
    event.preventDefault();
    first.focus();
  }
}

function clearRegistryFilters() {
  query.value = "";
  statusFilter.value = "";
}

function commentAvatarClass(item) {
  if (item.lastCommentAuthor === item.expert) return avatarRoleClass('expert')
  if (item.lastCommentAuthor === item.executor) return avatarRoleClass('ic_executor')
  return avatarRoleClass('employee')
}

function reloadFirstPage() {
  currentPage.value = 1;
  loadRequests();
}

watch([activeTab, activeAttention, statusFilter, sortDirection], reloadFirstPage);
watch(query, () => {
  registryLoadLifecycle.scheduleReload(reloadFirstPage);
});
watch(draft, draftForm.scheduleSave, { deep: true, flush: "sync" });
watch(draftFiles, draftForm.scheduleFilesSave, { flush: "sync" });
watch(
  [() => props.refreshTrigger, () => props.active],
  ([refreshTrigger, active], [previousRefreshTrigger, wasActive]) => {
    if (active) {
      if (!wasActive || refreshTrigger !== previousRefreshTrigger) {
        loadRequests();
        loadDashboard();
      }
      if (!wasActive) loadNotifications();
    }
    else if (wasActive) {
      registryLoadLifecycle.deactivate();
      showCreate.value = false;
      showNotifications.value = false;
      notificationsLoading.value = false;
      closeDashboardHelp({ restoreFocus: false });
    }
  },
);
function goToPage(page) {
  if (page === currentPage.value) return;
  currentPage.value = page;
  loadRequests();
}

function selectPageSize(size) {
  if (pageSize.value === size) return;
  pageSize.value = size;
  writeRegistryPageSize(size);
  reloadFirstPage();
}

async function downloadReport(item) {
  if (!item.reportVersionId || !item.reportOriginalName) return;
  const token = downloadGuard.begin(item.backendId);
  registryError.value = "";
  try {
    const blob = await requestApi.downloadDocument(item.reportVersionId);
    if (downloadGuard.isCurrent(token, item.backendId))
      triggerBlobDownload(blob, item.reportOriginalName);
  } catch {
    if (downloadGuard.isCurrent(token, item.backendId))
      registryError.value = "Не удалось скачать отчёт испытаний.";
  }
}

async function createRequest() {
  if (createLoading.value) return;
  createLoading.value = true;
  try {
    await performCreateRequest();
  } finally {
    createLoading.value = false;
  }
}

async function performCreateRequest() {
  createError.value = "";
  registryError.value = "";
  const token = createRequestGuard.begin(true);
  const isCurrent = () => createRequestGuard.isCurrent(token, true);
  let created;
  try {
    created = await requestApi.create(draft);
  } catch (error) {
    if (!isCurrent()) return;
    createError.value =
      error.status === 422
        ? "Проверьте обязательные поля формы."
        : error.status === 403
          ? "Ваш профиль не может подавать заявки. Обратитесь к администратору."
          : "Не удалось создать заявку. Повторите попытку.";
    return;
  }
  if (!isCurrent()) return;
  draftForm.remove();
  const failedFiles = [];
  for (const file of draftFiles.value) {
    try {
      await requestApi.uploadDocument(created.id, file);
      if (!isCurrent()) return;
    } catch (error) {
      if (!isCurrent()) return;
      failedFiles.push(
        `${file.name} (${error.status === 413 ? "файл слишком большой, максимум 10 МБ" : error.status === 422 ? "недопустимый формат или размер" : "ошибка загрузки"})`,
      );
    }
  }
  const comment = draft.comment.trim();
  let commentFailed = false;
  if (comment) {
    try {
      await requestApi.addComment(created.id, comment);
      if (!isCurrent()) return;
    } catch {
      if (!isCurrent()) return;
      commentFailed = true;
    }
  }
  resetCreateForm();
  try {
    if (!isCurrent()) return;
    await loadRequests({ rethrow: true });
    if (!isCurrent()) return;
    await loadDashboard();
    if (!isCurrent()) return;
    const createdItem = requests.value.find(
      (item) => item.backendId === created.id,
    );
    const warnings = [];
    if (!createdItem)
      warnings.push(
        "Заявка создана, но пока не появилась в реестре. Не создавайте её повторно. Обновите страницу.",
      );
    if (failedFiles.length || commentFailed)
      warnings.push(
        `Заявка создана.${failedFiles.length ? ` Не загружены: ${failedFiles.join(", ")}.` : ""}${commentFailed ? " Комментарий не удалось сохранить." : ""}`,
      );
    const warning = warnings.join(" ");
    if (createdItem) emit("select-request", createdItem, warning);
    else registryError.value = warning;
  } catch {
    if (isCurrent()) registryError.value =
      "Заявка создана, но обновить реестр не удалось. Не создавайте её повторно. Обновите страницу.";
  } finally {
    if (isCurrent()) showCreate.value = false;
  }
}

defineExpose({
  openCreate: () => {
    showCreate.value = true;
  },
});
onMounted(() => {
  draftForm.restore();
  window.addEventListener("pagehide", draftForm.flushSave);
  if (props.active) {
    loadRequests();
    loadDashboard();
    loadNotifications();
  }
});
onBeforeUnmount(() => {
  window.removeEventListener("pagehide", draftForm.flushSave);
  draftForm.dispose();
  registryLoadLifecycle.deactivate();
});
</script>

<template>
  <section v-show="active" class="page screen-panel" :class="{ 'screen-panel--active': active }">
    <section
      v-if="dashboardLoading || dashboardError || attentionCategories.length"
      class="attention-dashboard"
      aria-labelledby="attention-title"
      :aria-busy="dashboardLoading"
    >
      <div class="attention-heading">
        <div><h2 id="attention-title">Требуют вашего внимания <button ref="dashboardHelpTrigger" type="button" class="request-action-help attention-help" aria-label="Инструкция по заявкам, требующим внимания" title="Инструкция по заявкам, требующим внимания" @click="openDashboardHelp"><AppIcon name="help" :size="16" /></button></h2><p>Ваши текущие задачи по заявкам</p></div>
        <button v-if="dashboardError" type="button" class="attention-retry" @click="loadDashboard">Повторить</button>
      </div>
      <p v-if="dashboardError" class="attention-error" role="alert">{{ dashboardError }}</p>
      <div v-if="dashboardLoading && !attentionCategories.length" class="attention-grid attention-grid--loading" aria-label="Загрузка текущих задач">
        <span v-for="index in 3" :key="index" class="attention-skeleton" aria-hidden="true"></span>
      </div>
      <div v-else-if="attentionCategories.length" class="attention-grid">
        <button
          v-for="category in attentionCategories"
          :key="category.id"
          type="button"
          class="attention-card"
          :class="{ 'attention-card--active': activeAttention === category.id }"
          :aria-pressed="activeAttention === category.id"
          @click="selectAttention(category.id)"
        >
          <span class="attention-icon" aria-hidden="true"><AppIcon :name="attentionIcon(category.id)" :size="18" /></span>
          <span class="attention-copy"><b>{{ category.title }}</b><small>{{ category.description }}</small></span>
          <span class="attention-count" :aria-label="`${category.count} заявок`">{{ category.count }}</span>
        </button>
      </div>
    </section>
    <div v-if="registryError" class="detail-state error registry-error" role="alert">
      <span>{{ registryError }}</span>
      <button type="button" @click="loadRequests">Повторить</button>
    </div>
    <div class="card registry" :aria-busy="registryLoading">
      <span class="visually-hidden" aria-live="polite">{{ registryLoading ? "Загрузка реестра" : "Реестр загружен" }}</span>
      <div class="registry-head">
        <div class="tabs" role="tablist" aria-label="Представление реестра">
          <button
            v-for="tab in tabs"
            :key="tab.id"
            :class="{ active: activeTab === tab.id }"
            role="tab"
            :aria-selected="activeTab === tab.id"
            @click="activeTab = tab.id"
          >
            <span class="tab-label">{{ tab.label }}</span><span class="tab-count">{{ tab.count }}</span>
          </button>
        </div>
        <button class="primary tabs-cta" @click="showCreate = true">
          <AppIcon name="plus" :size="16" />
          Новая заявка
        </button>
      </div>
      <div class="toolbar">
        <label class="search">
          <AppIcon name="search" :size="17" />
          <input v-model="query" type="search" placeholder="Поиск по заявкам" aria-label="Поиск по заявкам" />
        </label>
        <label class="status-filter">
          <span class="visually-hidden">Статус заявки</span>
          <select v-model="statusFilter">
            <option value="">Все статусы</option>
            <option
              v-for="status in REQUEST_STATUS_OPTIONS"
              :key="status.value"
              :value="status.value"
            >
              {{ status.label }}
            </option>
          </select>
        </label>
        <button v-if="query || statusFilter" type="button" class="toolbar-clear" @click="clearRegistryFilters">
          Сбросить
        </button>
        <button type="button" class="registry-notifications" :aria-label="newNotifications.length ? 'Есть новые события в заявках' : 'Новых событий в заявках нет'" @click="openNotifications">
          <AppIcon name="bell" :size="18" /><span v-if="newNotifications.length" class="notification-dot" aria-hidden="true"></span>
        </button>
      </div>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th
                class="sortable"
                scope="col"
                :aria-sort="sortDirection === 'desc' ? 'descending' : 'ascending'"
              >
                <button
                  type="button"
                  class="sort-control"
                  @click="sortDirection = sortDirection === 'desc' ? 'asc' : 'desc'"
                >
                  <span>№ заявки</span><svg viewBox="0 0 16 16" aria-hidden="true" :class="{ ascending: sortDirection === 'asc' }"><path d="m5 6 3 3 3-3" /></svg>
                </button>
              </th>
              <th>Дата</th>
              <th>Объект испытаний</th>
              <th>Инициатор</th>
              <th>Исполнитель</th>
              <th>Статус</th>
              <th>СБ</th>
              <th class="registry-feed-cell">Комментарий</th>
              <th class="registry-indicator-cell">Отчёт</th>
            </tr>
          </thead>
          <tbody v-if="registryLoading && !paged.items.length" aria-label="Загрузка заявок">
            <tr v-for="index in 7" :key="`skeleton-${index}`" class="registry-skeleton" aria-hidden="true">
              <td v-for="cell in 9" :key="cell"><span /></td>
            </tr>
          </tbody>
          <tbody v-else>
            <tr
              v-for="item in paged.items"
              :key="item.id"
              :class="`row-color-${item.color}`"
              tabindex="0"
              @click="emit('select-request', item)"
              @keydown.enter="emit('select-request', item)"
              @keydown.space.prevent="emit('select-request', item)"
            >
              <td class="number">{{ item.id }}</td>
              <td>{{ item.date }}</td>
              <td class="registry-object-cell">
                <span class="registry-object-tooltip app-tooltip" :data-tooltip="item.product" tabindex="0"><span class="registry-object">{{ item.product }}</span></span><small :title="item.supplier">{{ item.supplier }}</small>
              </td>
              <td>
                {{ item.initiator
                }}<small :title="item.department">{{ item.department }}</small>
              </td>
              <td>{{ item.executor }}</td>
              <td>
                <span class="badge" :class="item.tone">{{ item.compactStatus }}</span>
              </td>
              <td class="registry-indicator-cell">
                <span
                  class="security-mark-icon"
                  :class="item.securityMarkDisplay?.className"
                  :title="item.securityMarkDisplay?.label"
                  :aria-label="item.securityMarkDisplay?.label"
                ><svg
                  viewBox="0 0 16 16"
                  width="14"
                  height="14"
                  fill="none"
                  stroke="currentColor"
                  stroke-width="2"
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  aria-hidden="true"
                  focusable="false"
                >
                  <path :d="item.securityMarkDisplay?.path" /></svg></span>
              </td>
              <td class="registry-feed-cell">
                <button
                  v-if="item.lastCommentAuthor"
                  type="button"
                  class="registry-comment-preview"
                  :title="item.lastCommentBody"
                  :aria-label="
                    'Последний комментарий: ' + item.lastCommentAuthor + '. ' + item.lastCommentBody
                  "
                  @click.stop="lastCommentModal = item"
                >
                  <span class="avatar small registry-comment-avatar" :class="commentAvatarClass(item)">
                    {{ initialsFor(item.lastCommentAuthor) }}
                  </span>
                  <span class="registry-comment-copy">{{ item.lastCommentBody }}</span>
                </button><span v-else class="muted-dash">—</span>
              </td>
              <td class="registry-indicator-cell">
                <button
                  v-if="
                    item.hasReport &&
                      item.reportVersionId &&
                      item.reportOriginalName
                  "
                  type="button"
                  class="registry-report-icon"
                  title="Скачать отчёт испытаний"
                  aria-label="Скачать отчёт испытаний"
                  @click.stop="downloadReport(item)"
                >
                  <AppIcon name="file" :size="16" />
                  <span class="registry-report-download" aria-hidden="true"><AppIcon name="download" :size="9" /></span>
                </button><span v-else class="muted-dash">—</span>
              </td>
            </tr>
          </tbody>
        </table>
        <div v-if="!registryLoading && !registryError && !paged.total" class="empty">
          <div class="empty-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="10.5" cy="10.5" r="6" /><path d="m15 15 4 4" /></svg></div>
          <h3>Ничего не найдено</h3>
          <p>{{ query || statusFilter ? "Измените запрос или сбросьте фильтры." : "В этом представлении пока нет заявок." }}</p>
          <button v-if="query || statusFilter" type="button" class="secondary empty-action" @click="clearRegistryFilters">Сбросить фильтры</button>
        </div>
      </div>
      <footer v-if="paged.total" class="pagination">
        <span v-if="paged.pageCount > 1" class="pagination-pages"><button
          :disabled="paged.page <= 1" aria-label="Предыдущая страница" @click="goToPage(paged.page - 1)"
        ><AppIcon name="chevron-left" :size="16" /></button><button
          v-for="page in pageNumbers" :key="page" :class="{ current: page === paged.page }" :aria-label="`Страница ${page}`" :aria-current="page === paged.page ? 'page' : undefined" @click="goToPage(page)"
        >{{ page }}</button><button
          :disabled="paged.page >= paged.pageCount" aria-label="Следующая страница" @click="goToPage(paged.page + 1)"
        ><AppIcon name="chevron-right" :size="16" /></button></span>
        <span class="pagination-range">{{ (paged.page - 1) * pageSize + 1 }}–{{
          Math.min(paged.page * pageSize, paged.total)
        }}
          из {{ paged.total }}</span>
        <div class="page-size-picker" role="group" aria-label="Заявок на странице">
          <span>На странице</span><button
            v-for="size in PAGE_SIZE_OPTIONS"
            :key="size"
            type="button"
            class="page-size-option"
            :class="[`page-size-option--${size}`, { current: pageSize === size }]"
            :aria-label="`Показывать по ${size} заявок`"
            :aria-pressed="pageSize === size"
            @click="selectPageSize(size)"
          >{{ size }}</button>
        </div>
      </footer>
    </div>
  </section>

  <div v-if="active && showDashboardHelp" class="request-drawer-overlay" @click.self="closeDashboardHelp()">
    <aside ref="dashboardHelpDrawer" class="request-drawer request-help-drawer" role="dialog" aria-modal="true" aria-labelledby="dashboard-help-title" @keydown="handleDashboardHelpKeydown">
      <header class="request-drawer-head"><div><p>Реестр заявок</p><h2 id="dashboard-help-title">Справка</h2></div><button type="button" aria-label="Закрыть справку" @click="closeDashboardHelp()"><AppIcon name="close" /></button></header>
      <HelpArticle src="/help/dashboard.html" />
    </aside>
  </div>
  <AppModal
    :open="active && showNotifications" title="Новые события в заявках" title-id="registry-notifications-title" size="medium" @close="closeNotifications"
  >
    <p v-if="notificationsLoading" class="notification-state" aria-live="polite">Загрузка событий…</p>
    <div v-else-if="notificationsError" class="notification-state error" role="alert"><span>{{ notificationsError }}</span><button type="button" class="secondary" @click="loadNotifications">Повторить</button></div>
    <div v-else-if="!notificationItems.length" class="notification-empty"><AppIcon name="bell" :size="24" /><b>Новых событий нет</b><span>Здесь появятся изменения в доступных вам заявках.</span></div>
    <ol v-else class="notification-list">
      <li v-for="item in notificationItems" :key="item.id"><button type="button" @click="selectNotification(item)"><span class="notification-request">Заявка №{{ item.requestNumber }}</span><b>{{ item.title }}</b><span class="notification-product">{{ item.productName }}</span><small>{{ item.authorName || 'Автор не указан' }} · {{ new Date(item.occurredAt).toLocaleString('ru-RU') }}</small><AppIcon name="chevron-right" :size="16" /></button></li>
    </ol>
  </AppModal>
  <AppModal
    :open="active && showCreate"
    as="form"
    title="Заявка на проведение испытаний"
    title-id="create-request-title"
    size="large"
    :busy="createLoading"
    @close="showCreate = false"
    @submit="createRequest"
  >
    <template #eyebrow><p class="eyebrow">Новая заявка</p></template>
    <div class="form-grid">
      <label>Объект испытаний *<input
        v-model="draft.productName"
        :disabled="createLoading"
        required
        maxlength="500"
        placeholder="Укажите наименование и тип продукции"
      /></label><label>Количество образцов *<input
        v-model.number="draft.sampleQuantity"
        :disabled="createLoading"
        required
        type="number"
        min="1"
      /></label><label>Производитель *<input
        v-model="draft.manufacturer"
        :disabled="createLoading"
        required
        maxlength="500"
        placeholder="Наименование производителя"
      /></label><label>Поставщик *<input
        v-model="draft.supplier"
        :disabled="createLoading"
        required
        maxlength="500"
        placeholder="Наименование поставщика"
      /></label><label class="wide">Метод испытаний *<textarea
        v-model="draft.testMethod"
        :disabled="createLoading"
        required
        maxlength="10000"
        placeholder="Опишите метод или программу испытаний"
      ></textarea></label><label class="wide">Сопроводительные документы
        <div class="dropzone">
          <input
            ref="draftFileInput"
            type="file"
            multiple
            accept=".pdf,.png,.jpg,.jpeg,.docx,.xlsx"
            :disabled="createLoading"
            @change="draftFiles = Array.from($event.target.files || [])"
          /><span>Перетащите файлы сюда или <b>выберите на компьютере</b></span><small v-if="draftFiles.length">Выбрано:
            {{ draftFiles.map((file) => file.name).join(", ") }}</small>
        </div></label><label class="wide">Комментарий<textarea
        v-model="draft.comment"
        :disabled="createLoading"
        maxlength="10000"
        placeholder="Добавьте пояснение к заявке"
      ></textarea>
      </label>
    </div>
    <p v-if="createNotice" class="form-notice" role="status">{{ createNotice }}</p>
    <p v-if="createError" class="form-error">{{ createError }}</p>
    <template #footer>
      <button
        type="button"
        class="secondary clear-draft"
        :disabled="createLoading"
        @click="clearCreateDraft"
      >
        Очистить черновик</button>
      <button
        type="button"
        class="secondary"
        :disabled="createLoading"
        @click="showCreate = false"
      >
        Отмена</button><button class="primary" :disabled="createLoading">
        {{ createLoading ? "Создание…" : "Создать заявку" }}
      </button>
    </template>
  </AppModal>
  <AppModal
    :open="confirmDialog.state.open"
    title="Подтвердите действие"
    title-id="registry-confirm-title"
    description-id="registry-confirm-message"
    size="small"
    alert
    @close="confirmDialog.cancel"
  >
    <p id="registry-confirm-message">{{ confirmDialog.state.message }}</p>
    <template #footer>
      <button class="secondary" @click="confirmDialog.cancel">Отмена</button><button class="primary danger" @click="confirmDialog.accept">
        {{ confirmDialog.state.confirmLabel }}
      </button>
    </template>
  </AppModal>
  <AppModal :open="active && Boolean(lastCommentModal)" title="Последний комментарий" title-id="last-comment-title" size="small" @close="lastCommentModal = null">
    <p v-if="lastCommentModal" class="comment-modal-meta">
      <b>{{ lastCommentModal.lastCommentAuthor }}</b> ·
      {{ lastCommentModal.lastCommentAt }}
    </p>
    <p v-if="lastCommentModal">{{ lastCommentModal.lastCommentBody }}</p>
    <template #footer>
      <button class="secondary" @click="lastCommentModal = null">
        Закрыть
      </button>
    </template>
  </AppModal>
</template>
