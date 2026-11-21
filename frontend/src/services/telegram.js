import { theme } from './theme'
/**
 * Thin wrapper over window.Telegram.WebApp. Every call is safe outside Telegram
 * (plain browser during development): it simply does nothing.
 */
const WebApp = window.Telegram?.WebApp
const at = (v) => !!WebApp && WebApp.isVersionAtLeast(v)

const handlers = { back: null, main: null, secondary: null }

export const tg = {
  raw: WebApp,
  get isTelegram() { return !!WebApp?.initData },
  get initData() { return WebApp?.initData || '' },
  get user() { return WebApp?.initDataUnsafe?.user || null },
  get startParam() { return WebApp?.initDataUnsafe?.start_param || null },
  get platform() { return WebApp?.platform || 'web' },
  get hasMainButton() { return !!WebApp?.initData && at('6.1') },
  get hasSecondaryButton() { return !!WebApp?.initData && at('7.10') },
  get canInvoice() { return !!WebApp?.initData && at('6.1') },
  get canDownload() { return !!WebApp?.initData && at('8.0') && typeof WebApp.downloadFile === 'function' },

  init() {
    this.applyTheme()
    if (!WebApp) return
    WebApp.ready()
    WebApp.expand()
    try { WebApp.disableVerticalSwipes?.() } catch { /* older clients */ }
    this.guardScroll()
    WebApp.onEvent('themeChanged', () => this.applyTheme())
    document.addEventListener('ng-theme', () => this.applyTheme())
  },

  applyTheme() {
    // Only light or dark is taken from Telegram. The palette stays ours: client themes (Telegram Desktop
    // on Windows above all) bring their own button and text colors, and those clash with the warm design.
    const root = document.documentElement
    const scheme = WebApp?.colorScheme || (matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark')
    root.dataset.theme = scheme
    if (!WebApp) return
    try {
      const hex = theme.value('--color-paper') || (scheme === 'light' ? '#f8f5f2' : '#0d0b0a')
      if (at('6.1')) { WebApp.setHeaderColor(hex); WebApp.setBackgroundColor(hex) }
      if (at('7.10')) WebApp.setBottomBarColor(hex)
    } catch { /* older clients */ }
  },
  /** Colors of the native bottom button, so it matches the in-app buttons in any client theme. */
  get cta() {
    const base = document.documentElement.dataset.theme === 'light'
      ? { color: '#221d1a', textColor: '#faf8f6' }
      : { color: '#f7f4f0', textColor: '#1f1a17' }
    return { color: theme.value('--color-cta') || base.color, textColor: theme.value('--color-cta-ink') || base.textColor }
  },

  /**
   * Keeps a drag at the top or bottom of a scroll area from turning into Telegram's swipe-to-close
   * (clients older than 7.7 cannot disable it): the list is always nudged 1px away from its edge.
   */
  guardScroll() {
    let el = null
    document.addEventListener('touchstart', (e) => {
      el = e.target.closest?.('.scroll, .sheet') || null
      if (!el) return
      if (el.scrollTop <= 0) el.scrollTop = 1
      else if (el.scrollTop + el.clientHeight >= el.scrollHeight) el.scrollTop = el.scrollHeight - el.clientHeight - 1
    }, { passive: true })
    // a drag that starts outside any scroll area must not move the page at all
    document.addEventListener('touchmove', (e) => {
      if (!e.target.closest?.('.scroll, .sheet, .trends, .filters, textarea, input')) e.preventDefault()
    }, { passive: false })
  },

  // ---- buttons ----
  showBack(visible, cb) {
    if (!WebApp || !at('6.1')) return
    if (handlers.back) WebApp.BackButton.offClick(handlers.back)
    handlers.back = cb
    WebApp.BackButton.onClick(cb)
    visible ? WebApp.BackButton.show() : WebApp.BackButton.hide()
  },
  setMain({ text, onClick, color, textColor, active = true, shine = false } = {}) {
    if (!this.hasMainButton) return
    const b = WebApp.MainButton
    if (handlers.main) b.offClick(handlers.main)
    handlers.main = null
    if (!text) return b.hide()
    handlers.main = onClick
    b.onClick(onClick)
    b.setParams({
      text, is_visible: true, is_active: active,
      color: color || this.cta.color, text_color: textColor || this.cta.textColor,
      ...(at('7.10') ? { has_shine_effect: shine } : {}),
    })
  },
  setSecondary({ text, onClick, destructive = false } = {}) {
    if (!this.hasSecondaryButton) return
    const b = WebApp.SecondaryButton
    if (handlers.secondary) b.offClick(handlers.secondary)
    handlers.secondary = null
    if (!text) return b.hide()
    handlers.secondary = onClick
    b.onClick(onClick)
    b.setParams({ text, is_visible: true, position: 'top', ...(destructive ? { text_color: '#ff453a' } : {}) })
  },

  // ---- haptics ----
  haptic(kind = 'light') {
    const h = WebApp?.HapticFeedback
    if (!h) return
    try {
      if (['light', 'medium', 'heavy', 'rigid', 'soft'].includes(kind)) h.impactOccurred(kind)
      else if (kind === 'selection') h.selectionChanged()
      else h.notificationOccurred(kind) // success | warning | error
    } catch { /* not supported */ }
  },

  // ---- navigation helpers ----
  openLink(url) {
    if (WebApp) WebApp.openLink(url)
    else window.open(url, '_blank')
  },
  openTelegramLink(url) {
    if (WebApp) WebApp.openTelegramLink(url)
    else window.open(url, '_blank')
  },
  /** Telegram Stars invoice. Resolves with paid | cancelled | failed | pending. */
  openInvoice(url) {
    return new Promise((resolve) => {
      if (!this.canInvoice) return resolve('failed')
      try { WebApp.openInvoice(url, (status) => resolve(status)) } catch { resolve('failed') }
    })
  },
  close() { WebApp?.close() },
  onVisible(cb) {
    document.addEventListener('visibilitychange', () => !document.hidden && cb())
    WebApp?.onEvent('activated', cb)
  },
  download(url, fileName) {
    if (WebApp && at('8.0')) WebApp.downloadFile({ url, file_name: fileName })
    else this.openLink(url)
  },
  shareStory(url) {
    if (WebApp && at('7.8')) { WebApp.shareToStory(url); return true }
    return false
  },
  share(url, text) {
    this.openTelegramLink(`https://t.me/share/url?url=${encodeURIComponent(url)}&text=${encodeURIComponent(text)}`)
  },
  async copy(text) {
    try { await navigator.clipboard.writeText(text); return true } catch { /* fall through */ }
    try {
      const ta = document.createElement('textarea')
      ta.value = text; ta.style.cssText = 'position:fixed;opacity:0'
      document.body.appendChild(ta); ta.select()
      const ok = document.execCommand('copy'); ta.remove()
      return ok
    } catch { return false }
  },
}
