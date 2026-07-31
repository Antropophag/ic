import { reactive } from 'vue'

export function createConfirmDialog() {
  const state = reactive({
    open: false,
    message: '',
    confirmLabel: 'Подтвердить',
    danger: false,
    // Согласовать и завершить — единственное положительное действие СБ —
    // остаётся зелёной и как кнопка-триггер, и как кнопка подтверждения в
    // модалке, чтобы цвет не терялся между двумя шагами (issue #153).
    confirm: false,
    // reasonField: { required, placeholder } | null — модалка показывает
    // textarea причины только когда задано (issue #153: причина отказа/
    // отзыва/возврата переезжает из вечно видимой textarea карточки в
    // модалку подтверждения). Без reasonField поведение не меняется.
    reasonField: null,
    reasonValue: '',
  })
  let resolveCurrent = null

  function ask(message, { confirmLabel = 'Подтвердить', danger = false, confirm = false, reasonField = null } = {}) {
    // Реентрантный вызов до ответа на предыдущий prompt отменяет его —
    // иначе резолвер первого промиса теряется, и await confirmDialog.ask()
    // вызывающей функции повисает навсегда.
    resolveCurrent?.(false)
    state.open = true
    state.message = message
    state.confirmLabel = confirmLabel
    state.danger = danger
    state.confirm = confirm
    state.reasonField = reasonField
    state.reasonValue = ''
    return new Promise(resolve => {
      resolveCurrent = resolve
    })
  }

  function settle(result) {
    state.open = false
    const resolve = resolveCurrent
    resolveCurrent = null
    resolve?.(result)
  }

  return {
    state,
    ask,
    // Без reasonField accept() резолвит true — как раньше, вызывающий код
    // не меняется. С reasonField резолвит { reason } — всегда truthy, даже
    // при пустой необязательной причине (иначе `if (!result) return`
    // ошибочно принял бы подтверждение с пустой причиной за отмену).
    accept: () => settle(state.reasonField ? { reason: state.reasonValue.trim() } : true),
    cancel: () => settle(false),
  }
}
