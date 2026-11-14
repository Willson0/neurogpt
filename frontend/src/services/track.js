import { watch } from 'vue'
import { api } from './api'

/**
 * Analytics for the admin dashboard: every button press and every screen of the Mini App is counted.
 *
 * A press is named by the element's data-track, otherwise by its aria-label or visible text with numbers removed
 * ("Оплатить 199 ₽" and "Оплатить 990 ₽" count as one button). The screen is the current view plus an open sheet.
 * Nothing the user types is sent: only buttons, links and chips are looked at. Events go in batches.
 */

const queue = []
let timer = null
let started = false

const clean = (text) => String(text || '')
  .replace(/\d[\d\s.,]*/g, ' ')
  .replace(/[₽⭐★%×+]/g, ' ')
  .replace(/\s+/g, ' ')
  .trim()
  .slice(0, 60)

export function buttonName(el) {
  const own = el.getAttribute('data-track')
  if (own) return own
  return clean(el.getAttribute('aria-label') || el.innerText || el.textContent || el.getAttribute('title')) || el.id || ''
}

function push(type, name, screen) {
  if (!name) return
  queue.push({ type, name, screen, t: Date.now() })
  if (queue.length >= 40) flush()
  else if (!timer) timer = setTimeout(flush, 5000)
}

export function flush(leaving = false) {
  clearTimeout(timer)
  timer = null
  if (!queue.length) return
  const now = Date.now()
  const batch = queue.splice(0, 50).map((e) => ({ type: e.type, name: e.name, screen: e.screen, ago: Math.min(600, Math.round((now - e.t) / 1000)) }))
  api.events(batch, leaving).catch(() => { /* analytics never bothers the user */ })
  if (queue.length) timer = setTimeout(flush, 1000)
}

/** Starts counting. `app` is the Pinia store (current screen and sheet). */
export function startTracking(app) {
  if (started) return
  started = true
  const screen = () => app.current + (app.sheet ? ':' + app.sheet.name : '')

  document.addEventListener('click', (e) => {
    const el = e.target.closest?.('button, a, [role="button"], label.pill-btn, label.chip')
    if (!el || el.closest('[data-no-track]')) return
    push('click', buttonName(el), screen())
  }, { capture: true, passive: true })

  watch(screen, (s) => push('view', s, s), { immediate: true })

  document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'hidden') flush(true) })
  addEventListener('pagehide', () => flush(true))
}
