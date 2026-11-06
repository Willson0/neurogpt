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

  init() {
    if (!WebApp) return
    WebApp.ready()
    WebApp.expand()
    if (at('7.7')) WebApp.disableVerticalSwipes()
    this.applyTheme()
    WebApp.onEvent('themeChanged', () => this.applyTheme())
  },

  /** Telegram colors drive the surfaces; the warm accent stays ours. */
  applyTheme() {
    const root = document.documentElement
    const scheme = WebApp?.colorScheme || (matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark')
    root.dataset.theme = scheme
    const p = WebApp?.themeParams || {}
    const set = (k, v) => v && root.style.setProperty(k, v)
    set('--color-page', p.secondary_bg_color || p.bg_color)
    set('--color-paper', p.bg_color)
    set('--color-surface', p.section_bg_color || p.secondary_bg_color)
    set('--color-surface-2', p.secondary_bg_color)
    set('--color-ink', p.text_color)
    set('--color-muted', p.hint_color)
    set('--color-rule', p.section_separator_color)
    set('--color-cta', p.button_color)
    set('--color-cta-ink', p.button_text_color)
    if (p.section_bg_color || p.secondary_bg_color) {
      set('--color-glass', `color-mix(in srgb, ${p.section_bg_color || p.secondary_bg_color} 72%, transparent)`)
    }
    if (!WebApp) return
    try {
      if (at('6.1') && p.bg_color) {
        WebApp.setHeaderColor('bg_color')
        WebApp.setBackgroundColor(p.bg_color)
      }
      if (at('7.10') && p.bg_color) WebApp.setBottomBarColor(p.bg_color)
    } catch { /* older clients */ }
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
      ...(color ? { color } : {}), ...(textColor ? { text_color: textColor } : {}),
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
    if (['light', 'medium', 'heavy', 'rigid', 'soft'].includes(kind)) h.impactOccurred(kind)
    else if (kind === 'selection') h.selectionChanged()
    else h.notificationOccurred(kind) // success | warning | error
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
    try { await navigator.clipboard.writeText(text) } catch { /* ignore */ }
  },
}
