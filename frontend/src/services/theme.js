// Color theme set in the admin panel: { dark: { '--color-accent': '#ff8a3d' }, light: { ... } }.
// It is written as a <style> after the app's own stylesheet, so it wins over the built-in palette.
const KEY = 'ng_theme'
const SAFE_VAR = /^--[a-z0-9-]+$/
const SAFE_COLOR = /^#[0-9a-f]{6}$/i

function block(vars) {
  const out = []
  for (const [name, value] of Object.entries(vars || {})) {
    if (SAFE_VAR.test(name) && SAFE_COLOR.test(value)) out.push(`${name}:${value}`)
  }
  // frosted cards follow the surface color
  if (vars?.['--color-surface'] && SAFE_COLOR.test(vars['--color-surface'])) {
    out.push(`--color-glass:color-mix(in oklab, ${vars['--color-surface']} 66%, transparent)`)
    out.push(`--color-skel:${vars['--color-surface']}`)
  }
  return out.join(';')
}

export const theme = {
  current: { dark: {}, light: {} },

  /** Applies a theme and remembers it, so the next start has the right colors before the first request. */
  apply(t, remember = true) {
    this.current = { dark: { ...(t?.dark || {}) }, light: { ...(t?.light || {}) } }
    let el = document.getElementById('ng-theme')
    if (!el) {
      el = document.createElement('style')
      el.id = 'ng-theme'
      document.head.appendChild(el)
    }
    const dark = block(this.current.dark)
    const light = block(this.current.light)
    el.textContent =
      (dark ? `:root{${dark}}` : '') +
      (light ? `:root[data-theme="light"]{${light}}@media (prefers-color-scheme: light){:root:not([data-theme="dark"]){${light}}}` : '')
    if (remember) {
      try { localStorage.setItem(KEY, JSON.stringify(this.current)) } catch { /* private mode */ }
    }
    document.dispatchEvent(new CustomEvent('ng-theme'))
  },

  restore() {
    try {
      const saved = JSON.parse(localStorage.getItem(KEY) || 'null')
      if (saved) this.apply(saved, false)
    } catch { /* nothing saved */ }
  },

  /** Override for one variable in the scheme on screen, or null. */
  value(name) {
    const scheme = document.documentElement.dataset.theme === 'light' ? 'light' : 'dark'
    const v = this.current[scheme]?.[name]
    return v && SAFE_COLOR.test(v) ? v : null
  },
}

/** Any CSS color (oklch included) as #rrggbb: painted on a canvas and read back. */
export function toHex(color) {
  const cv = document.createElement('canvas')
  cv.width = cv.height = 1
  const x = cv.getContext('2d', { willReadFrequently: true })
  x.fillStyle = '#000'
  x.fillStyle = color
  x.fillRect(0, 0, 1, 1)
  const [r, g, b] = x.getImageData(0, 0, 1, 1).data
  return '#' + [r, g, b].map((n) => n.toString(16).padStart(2, '0')).join('')
}
