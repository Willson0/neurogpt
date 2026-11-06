export const plural = (n, a, b, c) => {
  const m10 = n % 10, m100 = n % 100
  return m10 === 1 && m100 !== 11 ? a : m10 >= 2 && m10 <= 4 && (m100 < 10 || m100 >= 20) ? b : c
}
export const fmt = (n) => Number(n).toLocaleString('ru-RU')
export const tok = (n) => `${fmt(n)} ${plural(n, 'токен', 'токена', 'токенов')}`
export const reduceMotion = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches
export const replay = (el, cls) => {
  if (!el) return
  el.classList.remove(cls)
  void el.offsetWidth
  el.classList.add(cls)
}
export const tokenColor = (name) => getComputedStyle(document.documentElement).getPropertyValue(name).trim()
