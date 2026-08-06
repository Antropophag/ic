# Спецификация дизайн-языка

## Обратное проектирование дизайн-языка Service Desk

**Версия:** 1.0
**Источник:** 67 SVG-файлов из предоставленного архива
**Назначение:** документировать фактический визуальный язык, компонентную модель и композиционные правила эталонного продукта; подготовить проверяемую основу для проектирования Портала ИЦ без буквального копирования экранов.

---

# 1. Статус доказательств

## 1.1. Что восстановлено точно

- Цветовая палитра — из `Colors.svg`, где указаны HEX и opacity.
- Шкала интервалов — из `Spacing.svg`, где явно перечислены интервалы.
- Размеры кнопок, controls, pagination, checkbox, switch, drawer и ряда других компонентов — из геометрии component SVG.
- Набор вариантов и состояний компонентов — из досок компонентов.
- Макрокомпозиции экранов — из досок экранов продукта.

## 1.2. Что восстановлено с ограничениями

- Точная типографическая система: текст в SVG в основном переведён в контуры, поэтому данные о гарнитуре, насыщенности и исходных стилях текста отсутствуют.
- Анимация: статические SVG не содержат длительности, функции плавности и логики переходов.
- Адаптивность: архив показывает несколько широких настольных компоновок, но не даёт полного набора контрольных точек.
- Семантика доступности: SVG не отражает DOM, ARIA и управление с клавиатуры.

**Правило:** в документе точные значения, подтверждённые источниками помечены как **Подтверждено**; системные интерпретации — как **Выведено**.

---

# 2. Инвентаризация источников

Архив содержит две категории:

- **Доски компонентов:** Buttons, Tabs, Table, Drawer, Select, Sidebar, Status, Modal, Checkbox, Switch, Tag, Pagination, Header, Documents, Messages, Calendar и другие.
- **Доски экранов продукта:** реестры, карточки сущностей, формы, отчёты, календарь, управление статусами/организациями/полями, сообщения и история изменений.

Полный каталог приведён в приложении `component-inventory.csv`.

---

# 3. Философия языка

Дизайн-язык строится не на декоративных эффектах, а на пяти системных качествах:

1. **Содержимое прежде всего.** Главным визуальным объектом является рабочая сущность, таблица, сообщение или документ.
2. **Низкая визуальная энтропия.** Ограниченная палитра, повторяемые размеры, малая вариативность контейнеров.
3. **Объектно-ориентированный интерфейс.** Файл, человек, статус, событие, строка реестра и фильтр имеют устойчивую форму.
4. **Последовательное раскрытие.** Сложные функции раскрываются через drawer, modal, popover и tabs.
5. **Согласованная плотность.** Плотность меняется по задаче, но геометрия и иерархия остаются едиными.

---

# 4. Основы

## 4.1. Цветовая система — подтверждено

### Dark Blue

| Token | Value | Назначение |
|---|---|---|
| Dark Blue 100 | `#0B1623` | Основной текст, тёмные surfaces, sidebar |
| Dark Blue 50% | `rgba(11,22,35,.50)` | Secondary text |
| Dark Blue 25% | `rgba(11,22,35,.25)` | Disabled/quiet |
| Dark Blue 10% | `rgba(11,22,35,.10)` | Subtle separators/overlays |

### Blue

| Token | Value | Назначение |
|---|---|---|
| Blue 300 | `#162773` | Active/pressed dark |
| Blue 200 | `#253D98` | Primary interaction |
| Blue 200 / 15% | `rgba(37,61,152,.15)` | Selected background |
| Blue 100 | `#DFE2F0` | Secondary selected surface |
| Blue 50 | `#EEF0F4` | Quiet controls/background |

### Gray

| Token | Value |
|---|---|
| Gray 200 | `#939CA5` |
| Gray 100 | `#D1D8DF` |
| Gray 75 | `#E0E0E0` |
| Gray 50 | `#F5F5F5` |

### Background

| Token | Value |
|---|---|
| Primary | `#F4F6F9` |
| Filter | `#EEF0F4` |
| Secondary | `#DFE2F0` |

### Semantic colors

Exact semantic pairs are построены по формуле **100% foreground + 15% background**:

`#CC1F1F`, `#25983E`, `#57965C`, `#9B7E46`, `#D47E2E`, `#245B99`, `#3D88DE`, `#4191B3`, `#8131A7`, `#A942A7`.

### Правила применения

- Primary blue используется для action, active navigation, focus и selection.
- Semantic color не используется для крупных декоративных surfaces.
- Status badge использует насыщенный foreground и 15% background.
- Neutral gray формирует большую часть интерфейса.
- Disabled state достигается opacity и снижением контраста, а не отдельным ярким цветом.

## 4.2. Spacing — Подтверждено

Исходная шкала из `Spacing.svg`:

`4, 8, 16, 24, 32, 40, 48, 56, 64 px`.

### Семантика шкалы — Выведено

| Token | Назначение |
|---|---|
| 4 | icon/text micro-gap |
| 8 | связанная пара элементов |
| 16 | внутренний padding control/card |
| 24 | padding секции |
| 32 | разделение крупных зон |
| 40 | control/header module |
| 48 | крупная внутренняя зона |
| 56 | row/toolbar rhythm |
| 64 | page-level separation |

**Запрет:** значения 6, 10, 14, 18, 20, 28 и другие промежуточные числа не должны появляться без доказанной оптической причины.

## 4.3. Radius — Подтверждено + normalized

SVG содержит технически точные радиусы, включая дробные значения, появившиеся после scaling/export. Для продуктовой системы они нормализуются:

| Семантика | Source evidence | Нормализованный token |
|---|---:|---:|
| Checkbox | 5.25 | 5–6 |
| Small tile | 8 | 8 |
| Surface / textarea | 8 / 16 | 8 или 16 по уровню |
| Medium control | 16 | 16 |
| Small pill | 13 | 13 |
| Medium pill | 16 | 16 |
| Large pill / 40px control | 20 | 20 |
| Circle | 50% | 999 |

Главное правило: радиус равен примерно половине высоты у pills и icon buttons; rectangular content objects используют 8–16 px.

## 4.4. Stroke and borders — Подтверждено

- Основной icon stroke: `1.5 px`.
- Вторичный распространённый stroke: `1.6875 px` — следствие scale, нормализуется до 1.5/1.75.
- Hairline divider: 1 px.
- Checkbox border: `#D1D8DF`.
- Focus/selected border: `#253D98`.

## 4.5. Elevation — Выведено from SVG filters

Архив содержит filters, Gaussian blur и offsets, но экспорт не даёт удобной исходной шкалы имён. Поведение подтверждает три уровня:

1. **Flat:** canvas, table rows, sections.
2. **Raised:** popover/dropdown/local floating panel.
3. **Overlay:** modal/drawer over dimmed background.

Тень не является постоянным оформлением каждой card.

## 4.6. Typography — Partially inferred

Font metadata отсутствует, так как текст переведён в контуры.

### Подтверждённые свойства

- Sans-serif grotesk.
- Большие page titles имеют normal/semibold weight.
- Body преимущественно regular.
- Table headings малы, серые, часто uppercase.
- Metadata и labels имеют сниженный контраст.
- Heavy bold используется редко.

### Рабочая семантическая шкала

| Style | Рекомендуемый диапазон |
|---|---|
| Display / board title | 48–64 |
| Page title | 28–36 |
| Section title | 20–24 |
| Card title | 16–20 |
| Body | 14–16 |
| Compact/table | 12–14 |
| Label/metadata | 10–12 |

Эти значения нельзя считать исходными Figma tokens; при переносе в Портал ИЦ нужно провести browser calibration.

---

# 5. Структура и состояния компонентов

## 5.1. Buttons — Подтверждено

`Buttons.svg` документирует три families:

- **Primary**
- **Secondary**
- **Text**

Каждая family имеет:
- Label variant
- Icon variant
- Large / Medium / Small
- Default / Hover / Active / Disabled

### Exact dimensions

| Size | Label example | Icon button | Radius |
|---|---:|---:|---:|
| Large | 142×40 | 40×40 | 20 |
| Medium | 131×32 | 32×32 | 16 |
| Small | 121×26 | — | 13 |

### Interaction hierarchy

- Primary: `#253D98`, active darkens toward `#162773`.
- Secondary: quiet gray/white surface.
- Text: minimal chrome.
- Disabled: reduced opacity/contrast.
- Icon action всегда получает отдельный fixed hit area.

### Rule

В одной локальной action group допускается одна primary action.

## 5.2. Tabs — Подтверждено

Система содержит два основных типа:

### Type 1 — underline tabs

- Используются для переключения workspace views.
- Active: dark text + blue underline.
- Inactive: gray text.
- Group mode: единая baseline across all tabs.
- Count может быть текстовым или badge-like.

### Type 2 — pills / attached tabs

- Pill tabs: selected background `#DFE2F0`, outlined/rest variants.
- Attached tabs: tab visually attaches to content surface.
- Disabled state явно присутствует.

### Rule

Underline tabs — режимы списка/раздела. Attached tabs — представления одной сущности.

## 5.3. Inputs and Select — Подтверждено

`Select.svg` показывает:

- width variants 250/280 px;
- heights 40/32 px;
- rest, focus, disabled, multi-select;
- chips внутри поля;
- count badge для скрытых значений;
- dropdown search;
- selected rows;
- bulk actions.

### Exact geometry

- Large select: 280×40 или 250×40.
- Medium select: 280×32 или 250×32.
- 40px control radius: 20.
- 32px control radius: 16.
- Selected chips: около 27 px high, radius около 13.5.
- Focus border: primary blue.

## 5.4. Textarea — Подтверждено

- Typical board variants: около 395×58.
- Radius: 8.
- Rest background uses neutral gray.
- Focus: primary border.
- Error: red border.
- Disabled/read-only variants included.

## 5.5. Checkbox — Подтверждено

- Large: 20×20, inner geometry 18.5, radius 5.25.
- Small: 16×16, inner geometry 14.5, radius 3.25.
- Default / checked / hover / disabled states.
- Selected: primary fill + white check.

## 5.6. Switch — Подтверждено

- Main: 38×20, thumb 16.
- Compact: 24×14, thumb about 11.2.
- On/off, enabled/disabled states.
- Primary blue for on, gray for off.

## 5.7. Status — Подтверждено

- Height 30 px.
- Radius 15.
- Width content-dependent.
- Uses semantic foreground and soft background.
- Includes multiple product statuses; status color is semantic, not decorative.

## 5.8. Badge — Подтверждено

Several badge geometries:

- 16px compact badges.
- 23px badges.
- 30px circular badge.
- Primary, neutral, outlined and disabled variants.

## 5.9. Tags — Подтверждено

- Standard tag around 29–30 px high.
- Text + optional avatar/icon + close.
- Neutral/outlined and selected variants.
- Long tags expand by content; chips remain single-line.

## 5.10. Pagination — Подтверждено

- Main button: 40×40, radius 20.
- Inner/alternate geometry: 39×39, radius 19.5.
- States: default, active, disabled.
- Pagination is a component inside the collection surface.

## 5.11. Drawer — Подтверждено

`Drawer.svg` documents:

- Content width around 420 px inside a 580px board.
- Surface radius 16.
- Close button 32×32.
- Footer with two equal 180×40 actions.
- Fixed header, swappable scroll body, fixed footer.
- Main action primary; back/cancel secondary.

### Behavioral rule

Drawer используется для длинной настройки, filter workflow, history и supporting context — не для короткого подтверждения.

## 5.12. Modal — Подтверждено

- Core modal width around 572 px.
- Radius 16.
- Close 32×32.
- Short forms/actions.
- Footer actions typically 32px high on board variants.
- Modal не должен превращаться в длинную страницу.

## 5.13. Sidebar — Подтверждено

- Collapsed global rail: 72 px.
- Item hit area: 44×44.
- Inner icon frame around 32×32.
- Radius active tile: 8.
- Dark surface `#0B1623`.
- Divider line and grouped navigation.
- Expanded contextual panel variants also present.

## 5.14. Header — Подтверждено

- Avatar: 48×48, radius 24.
- Header is a shell-level region on page background.
- Contains title/context actions/profile.
- User identity is visually stable across screens.

## 5.15. Table — Подтверждено

`Table.svg` includes multiple table families: appeals, statuses, organizations, profiles, categories, fields, auto-assignment and dictionaries.

### Shared anatomy

- Header row.
- Optional selection checkbox.
- Object row.
- Hover/selected row surface.
- Inline edit/action icons.
- Status/switch as embedded components.
- Thin horizontal separators.
- No heavy vertical grid.

### Exact recurring geometry

- Many rows: 50 px.
- Checkbox: 20×20.
- Status: 30 px.
- Selected/hover row: subtle gray surface.
- Table adapts columns but preserves row rhythm.

### Rule

Table is an object collection, not a spreadsheet. The primary object label must dominate technical metadata.

## 5.16. Files/Documents — Подтверждено

The archive contains dedicated `Documents.svg` and file treatments in messages/product screens.

Shared anatomy:
- file-type marker;
- filename;
- size/metadata;
- action;
- thumbnail or page object where available.

### Rule

Файл — самостоятельный product object. Plain underlined filename is insufficient for primary file presentation.

## 5.17. Messages — Подтверждено

- Avatar + author + time.
- Text on open white surface; no decorative speech bubbles.
- Attachments sit under the message.
- Composer is a distinct input zone.
- Rich composer may expose formatting/templates/documents progressively.
- System events are visually quieter than human messages.

## 5.18. History of changes — Подтверждено

- Right-side timeline/drawer pattern.
- Actor name + action + changed values + timestamp.
- Vertical line and nodes.
- Before/after values can be represented as badges/chips.
- History is not mixed with the primary content by default.

## 5.19. Popover / Tooltip / Dropdown menu — Подтверждено

- Popover: local floating surface with stronger elevation.
- Tooltip: short explanation only.
- Dropdown menu: compact vertical action list.
- These components do not own page-level workflows.

## 5.20. Calendar / Planner / Dashboard — Подтверждено

- Calendar is a dense matrix with row categories and date columns.
- Planner uses structured time/resource grids.
- Dashboard cards provide summaries but remain subordinate to the main task.
- Semantic colors distinguish event types, not decoration.

---

# 6. Композиционные паттерны

## 6.1. Collection page

Structure:

1. Page title and profile on canvas.
2. Large white workspace.
3. Tabs and utility actions.
4. Saved filters or search controls.
5. Data collection.
6. Pagination within the same workspace.

No independent card is inserted between each layer.

## 6.2. Entity page

Structure:

1. Back + entity title + contextual actions.
2. Attached tabs.
3. Main content surface.
4. Wide primary column.
5. Narrow contextual aside.
6. Attachments/messages/details as task-based views.

## 6.3. Edit/create page

- Large single form surface.
- Two-column field grid.
- Section headers with whitespace.
- Upload zone and file objects.
- Footer actions anchored to bottom of surface.

## 6.4. Overlay workflow

- Drawer for filters, history, long supporting settings.
- Modal for short atomic operations.
- Popover for local configuration.
- Dropdown for a small action set.

## 6.5. Conversation page

- Header and tabs.
- Reading column.
- Attachments inline.
- Composer at bottom.
- Secondary tools progressively revealed.

---

# 7. Information hierarchy rules

1. Page title is always the first visual fixation.
2. Active navigation is the second.
3. Primary data object/status is the third.
4. Labels and metadata are intentionally quiet.
5. Technical identifiers are subordinate unless they are the user’s primary lookup key.
6. One saturated primary action per local context.
7. Color area remains small.
8. Empty space is permitted and meaningful.
9. Independent objects get boundaries; semantic groups get spacing.
10. Repeated patterns must not mutate between admin and end-user areas.

---

# 8. Interaction model

## 8.1. Persistent

- Page identity.
- Active view.
- Primary collection/entity.
- Current status.
- Main action.
- Search on collection pages.

## 8.2. Progressive

- Advanced filters.
- Column settings.
- History.
- Rare destructive actions.
- Secondary file actions.
- Rich message tools.
- Long configuration.

## 8.3. States required for every component

- Default
- Hover
- Active/pressed
- Focus-visible
- Selected/checked
- Disabled
- Loading where applicable
- Error where applicable
- Empty where applicable

Доски компонентов directly document most visual states for Buttons, Select, Checkbox, Switch and Tabs.

---

# 9. Accessibility requirements inferred for implementation

SVG sources do not contain accessibility semantics, but geometry supports these implementation rules:

- 40×40 primary pointer target; 32×32 only for compact desktop contexts.
- Visible keyboard focus must use primary border/ring, not color alone.
- Status meaning must have text, not rely only on hue.
- Table headers must remain programmatically associated with cells.
- Drawers/modals require focus trap and escape.
- Tabs require roving tabindex/arrow navigation.
- Icon-only controls require accessible names.
- Disabled controls must remain distinguishable without excessive opacity loss.

---

# 10. Motion specification status

**Not recoverable from sources.**

Recommended validation target for future interactive prototype:

- Hover/focus: 100–160 ms.
- Popover/modal opacity: 140–200 ms.
- Drawer slide: 180–260 ms.
- Accordion/content expansion: 180–240 ms.
- Easing should be restrained and non-bouncy.

These values are recommendations, not source-derived tokens.

---

# 11. Anti-patterns forbidden by this language

- Card soup.
- Strong shadows on every container.
- Multiple primary buttons in one group.
- Large color-filled dashboard panels in routine work screens.
- Vertical grid lines in ordinary tables.
- Controls with arbitrary heights/radii.
- Files shown only as text links.
- Heavy bold on routine data.
- Human comments mixed indiscriminately with system audit events.
- Permanent exposure of advanced filters.
- A separate visual language for administration.
- Decorative gradients in data-heavy workspaces.
- Icon glyphs without stable hit areas.
- Page-level workflows inside popovers.

---

# 12. Adaptation boundary for the Portal IC

The Service Desk language can be transferred as:

- foundations;
- component geometry;
- hierarchy;
- collection/entity patterns;
- overlay model;
- status treatment;
- file object model;
- conversation/audit separation.

It must **not** be copied as:

- Service Desk terminology;
- exact screen layouts unrelated to testing workflow;
- category/status names;
- unnecessary calendar/planner functions;
- message co-author model unless required;
- full sidebar if Portal IC information architecture is smaller.

---

# 13. Design system governance

## 13.1. Token policy

- No raw color outside tokens.
- No spacing outside the observed 4/8/16/24/32/40/48/56/64 scale without documented exception.
- Control heights drawn from 26/32/40.
- Status height fixed at 30.
- Table row baseline 50.
- Radius tied to component height/level.

## 13.2. Component policy

Every component must define:
- anatomy;
- variants;
- states;
- content constraints;
- keyboard behavior;
- empty/loading/error behavior;
- responsive behavior;
- allowed composition contexts.

## 13.3. Change policy

A new component is allowed only if:
- existing component cannot express the task;
- composition cannot solve it;
- new behavior recurs in at least two product contexts, or is business-critical.

---

# 14. Confidence matrix

| Area | Confidence | Reason |
|---|---|---|
| Colors | High | Explicit HEX/opacity in source board |
| Spacing | High | Explicit scale in source board |
| Button sizes/states | High | Exact SVG geometry and variants |
| Form control sizes/states | High | Exact досок компонентов |
| Table rhythm | High | Repeated across many boards |
| Radius normalization | Medium-high | Exact values include export fractions |
| Typography family | Low | Text converted to paths |
| Typography hierarchy | High | Repeated visual evidence |
| Elevation behavior | High | Filters and overlay compositions |
| Exact shadow tokens | Medium | Filters exist but lack semantic names |
| Motion | Low | Static sources |
| Responsive behavior | Medium-low | Mostly desktop boards |
| Accessibility semantics | Not present | Must be specified in implementation |

---

# 15. Deliverables generated

- `Design_Language_Specification.md` — this document.
- `Design_Language_Specification.html` — readable standalone version.
- `design-tokens.json` — machine-readable token proposal with confidence metadata.
- `component-inventory.csv` — all 67 source SVG files, decoded names and classification.
- `source-analysis.json` — provenance and measured component facts.
