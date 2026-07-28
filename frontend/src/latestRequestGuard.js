export function createLatestRequestGuard() {
  let generation = 0

  return {
    begin(key) {
      return { generation: ++generation, key }
    },
    isCurrent(token, key) {
      return token.generation === generation && token.key === key
    },
    invalidate() {
      generation += 1
    },
  }
}
