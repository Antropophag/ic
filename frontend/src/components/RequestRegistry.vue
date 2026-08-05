<script setup>
import {
  computed,
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
import { createLatestRequestGuard } from "../latestRequestGuard";
import {
  REGISTRY_PAGE_SIZE,
  REQUEST_STATUS_OPTIONS,
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
const activeTab = ref("active");
const query = ref("");
const statusFilter = ref("");
const sortDirection = ref("desc");
const currentPage = ref(1);
const pageSize = REGISTRY_PAGE_SIZE;
const requests = ref([]);
const registryPage = reactive({
  total: 0,
  page: 1,
  pageSize,
  pageCount: 1,
  counts: { active: 0, all: 0, mine: 0 },
});
const registryError = ref("");
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
const registryGuard = createLatestRequestGuard();
const downloadGuard = createLatestRequestGuard();
const createRequestGuard = createLatestRequestGuard();
const confirmDialog = createConfirmDialog();
const draftForm = createApplicationDraftForm({
  userId: props.currentUserId,
  draft,
  files: () => draftFiles.value,
  notify: message => { createNotice.value = message; },
});
let searchTimer = null;

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
    "Очистить заполненные поля и сохранённый черновик?",
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
  try {
    const result = await requestApi.list({
      page: currentPage.value,
      pageSize,
      tab: activeTab.value,
      status: statusFilter.value,
      query: query.value.trim(),
      sort: sortDirection.value,
    });
    if (!registryGuard.isCurrent(token, true)) return;
    registryError.value = "";
    requests.value = result.items.map(fromApi);
    Object.assign(registryPage, {
      total: result.total ?? result.items.length,
      page: result.page ?? 1,
      pageSize: result.pageSize ?? pageSize,
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
  }
}

function reloadFirstPage() {
  currentPage.value = 1;
  loadRequests();
}

watch([activeTab, statusFilter, sortDirection], reloadFirstPage);
watch(query, () => {
  window.clearTimeout(searchTimer);
  searchTimer = window.setTimeout(reloadFirstPage, 300);
});
watch(draft, draftForm.scheduleSave, { deep: true, flush: "sync" });
watch(draftFiles, draftForm.scheduleFilesSave, { flush: "sync" });
watch(
  () => props.refreshTrigger,
  () => {
    if (props.active) loadRequests();
  },
);
watch(
  () => props.active,
  (active) => {
    if (active) loadRequests();
  },
);
function goToPage(page) {
  if (page === currentPage.value) return;
  currentPage.value = page;
  loadRequests();
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
  createError.value = "";
  registryError.value = "";
  createLoading.value = true;
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
    createLoading.value = false;
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
    await loadRequests({ rethrow: true });
    if (!isCurrent()) return;
    const createdItem = requests.value.find(
      (item) => item.backendId === created.id,
    );
    const warnings = [];
    if (!createdItem)
      warnings.push(
        "Заявка создана, но пока не появилась в реестре. Не создавайте её повторно; обновите страницу.",
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
      "Заявка создана, но обновить реестр не удалось. Не создавайте её повторно; обновите страницу.";
  } finally {
    if (isCurrent()) {
      showCreate.value = false;
      createLoading.value = false;
    }
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
  loadRequests();
});
onBeforeUnmount(() => {
  window.clearTimeout(searchTimer);
  window.removeEventListener("pagehide", draftForm.flushSave);
  draftForm.dispose();
  registryGuard.invalidate();
  downloadGuard.invalidate();
  createRequestGuard.invalidate();
});
</script>

<template>
  <section v-show="active" class="page">
    <p v-if="registryError" class="detail-state error">{{ registryError }}</p>
    <div class="card registry">
      <div class="tabs">
        <button
          v-for="tab in tabs"
          :key="tab.id"
          :class="{ active: activeTab === tab.id }"
          @click="activeTab = tab.id"
        >
          {{ tab.label }} <span>{{ tab.count }}</span></button><button class="primary tabs-cta" @click="showCreate = true">
          ＋ Новая заявка
        </button>
      </div>
      <div class="toolbar">
        <label class="search">⌕ <input v-model="query" placeholder="Поиск по заявкам" /></label><select v-model="statusFilter">
          <option value="">Все статусы</option>
          <option
            v-for="status in REQUEST_STATUS_OPTIONS"
            :key="status.value"
            :value="status.value"
          >
            {{ status.label }}
          </option>
        </select>
      </div>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th
                class="sortable"
                @click="
                  sortDirection = sortDirection === 'desc' ? 'asc' : 'desc'
                "
              >
                № заявки {{ sortDirection === "desc" ? "↓" : "↑" }}
              </th>
              <th>Дата</th>
              <th>Объект испытаний</th>
              <th>Инициатор</th>
              <th>Исполнитель</th>
              <th>Статус</th>
              <th>СБ</th>
              <th class="registry-indicator-cell">Комментарий</th>
              <th class="registry-indicator-cell">Отчёт</th>
            </tr>
          </thead>
          <tbody>
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
              <td>
                <b>{{ item.product }}</b><small :title="item.supplier">{{ item.supplier }}</small>
              </td>
              <td>
                {{ item.initiator
                }}<small :title="item.department">{{ item.department }}</small>
              </td>
              <td>{{ item.executor }}</td>
              <td>
                <span class="badge" :class="item.tone">{{ item.status }}</span>
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
              <td class="registry-indicator-cell">
                <button
                  v-if="item.lastCommentAuthor"
                  type="button"
                  class="avatar small registry-comment-avatar"
                  :title="'Последний комментарий: ' + item.lastCommentAuthor"
                  :aria-label="
                    'Последний комментарий: ' + item.lastCommentAuthor
                  "
                  @click.stop="lastCommentModal = item"
                >
                  {{ initialsFor(item.lastCommentAuthor) }}</button><span v-else class="muted-dash">—</span>
              </td>
              <td class="registry-indicator-cell">
                <button
                  v-if="
                    item.hasReport &&
                      item.reportVersionId &&
                      item.reportOriginalName
                  "
                  type="button"
                  class="doc-icon pdf registry-report-icon"
                  title="Скачать отчёт испытаний"
                  aria-label="Скачать отчёт испытаний"
                  @click.stop="downloadReport(item)"
                >
                  PDF</button><span v-else class="muted-dash">—</span>
              </td>
            </tr>
          </tbody>
        </table>
        <div v-if="!paged.total" class="empty">
          <div>⌕</div>
          <h3>Ничего не найдено</h3>
          <p>Измените запрос или очистите фильтры</p>
        </div>
      </div>
      <footer v-if="paged.total" class="pagination">
        <span>{{ (paged.page - 1) * pageSize + 1 }}–{{
          Math.min(paged.page * pageSize, paged.total)
        }}
          из {{ paged.total }}</span><span><button
          :disabled="paged.page <= 1"
          @click="goToPage(paged.page - 1)"
        >
          ‹</button><button
          v-for="page in pageNumbers"
          :key="page"
          :class="{ current: page === paged.page }"
          @click="goToPage(page)"
        >
          {{ page }}</button><button
          :disabled="paged.page >= paged.pageCount"
          @click="goToPage(paged.page + 1)"
        >
          ›
        </button></span>
      </footer>
    </div>
  </section>

  <div
    v-if="active && showCreate"
    class="overlay"
    @click.self="!createLoading && (showCreate = false)"
  >
    <form class="modal" @submit.prevent="createRequest">
      <div class="modal-head">
        <div>
          <p class="eyebrow">Новая заявка</p>
          <h2>Проведение испытаний</h2>
        </div>
        <button
          type="button"
          :disabled="createLoading"
          @click="showCreate = false"
        >
          ×
        </button>
      </div>
      <div class="form-grid">
        <label>Наименование и тип *<input
          v-model="draft.productName"
          required
          maxlength="500"
          placeholder="Введите наименование продукции"
        /></label><label>Количество образцов *<input
          v-model.number="draft.sampleQuantity"
          required
          type="number"
          min="1"
        /></label><label>Производитель *<input
          v-model="draft.manufacturer"
          required
          maxlength="500"
          placeholder="Наименование производителя"
        /></label><label>Поставщик *<input
          v-model="draft.supplier"
          required
          maxlength="500"
          placeholder="Наименование поставщика"
        /></label><label class="wide">Метод испытаний *<textarea
          v-model="draft.testMethod"
          required
          maxlength="10000"
          placeholder="Опишите метод или программу испытаний"
        ></textarea></label><label class="wide">Сопроводительная документация
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
          placeholder="Дополнительная информация"
        ></textarea>
        </label>
      </div>
      <p v-if="createNotice" class="form-notice" role="status">{{ createNotice }}</p>
      <p v-if="createError" class="form-error">{{ createError }}</p>
      <div class="modal-actions">
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
      </div>
    </form>
  </div>
  <div
    v-if="confirmDialog.state.open"
    class="overlay"
    @click.self="confirmDialog.cancel"
  >
    <div class="modal confirm-modal">
      <p>{{ confirmDialog.state.message }}</p>
      <div class="modal-actions">
        <button class="secondary" @click="confirmDialog.cancel">Отмена</button><button class="primary danger" @click="confirmDialog.accept">
          {{ confirmDialog.state.confirmLabel }}
        </button>
      </div>
    </div>
  </div>
  <div
    v-if="active && lastCommentModal"
    class="overlay"
    @click.self="lastCommentModal = null"
  >
    <div class="modal confirm-modal">
      <div class="modal-head">
        <h2>Последний комментарий</h2>
        <button @click="lastCommentModal = null">×</button>
      </div>
      <p class="comment-modal-meta">
        <b>{{ lastCommentModal.lastCommentAuthor }}</b> ·
        {{ lastCommentModal.lastCommentAt }}
      </p>
      <p>{{ lastCommentModal.lastCommentBody }}</p>
      <div class="modal-actions">
        <button class="secondary" @click="lastCommentModal = null">
          Закрыть
        </button>
      </div>
    </div>
  </div>
</template>
