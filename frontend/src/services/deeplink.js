import { tg } from './telegram'
import { api } from './api'
import { openAdmin } from '../stores/app'

/**
 * Opening the app on a given screen ("deep link").
 *
 * The same value works everywhere, so the bot developer learns one syntax:
 *   - direct Mini App link:   https://t.me/<bot>/<app>?startapp=<link>  (also https://t.me/<bot>?startapp=<link> for the Main App)
 *     Telegram hands it over as initData.start_param;
 *   - web_app buttons (inline keyboard, menu button): https://<app>/?startapp=<link>
 *     the URL is opened as is, so the value is read from the query string.
 *
 * <link> is "<screen>" or "<screen>-<argument>", only A-Z a-z 0-9 _ - (what Telegram allows in startapp, up to 64 chars):
 *   home | gallery | profile | history | invite | more
 *   image | video | song | tools               a tab of the composer on the home screen
 *   image-<model> | video-<model> | song-<model>               that tab with the model chosen (detail, kling, seedance-mini ...)
 *   models | models-video                       the model list
 *   tool-<id>                                   a tool sheet (upscale, remove_bg, animate ...)
 *   photoset-<id>                               a photoset (number from the admin panel)
 *   topup | topup-<tokens>                      buying tokens, optionally with a pack chosen
 *   plans | plans-<shop item id>               subscriptions
 *   result-<id>                                 one generation of this user
 *   admin | admin-<section>                     admin panel (admins only): dashboard, content, models, tools, showcase,
 *                                               pricing, settings, payments, theme, users
 * Referral values (friend_<id>, ref_<id>) are not screens: the app opens on the home screen and the server credits the invite.
 * An unknown value also opens the home screen.
 */

const PATTERN = /^[A-Za-z0-9_-]{1,64}$/

/** The raw link the app was opened with, or null. initData wins: it is signed by Telegram. */
export function readLink() {
  const q = new URLSearchParams(location.search)
  const candidates = [tg.startParam, q.get('startapp'), q.get('tgWebAppStartParam'), q.get('screen')]
  const raw = candidates.find((v) => typeof v === 'string' && PATTERN.test(v))
  return raw || null
}

/** Free text for the prompt, only from a web_app URL (?prompt=...): startapp cannot carry spaces. */
export function readPrompt() {
  const p = new URLSearchParams(location.search).get('prompt')
  return p ? p.slice(0, 2000) : ''
}

export function parseLink(raw) {
  if (!raw || /^(friend|ref)_\d+$/.test(raw)) return null
  const i = raw.indexOf('-')
  const screen = (i < 0 ? raw : raw.slice(0, i)).toLowerCase()
  const arg = i < 0 ? '' : raw.slice(i + 1)
  return { screen, arg }
}

const ADMIN_SECTIONS = ['dashboard', 'content', 'models', 'tools', 'showcase', 'pricing', 'settings', 'payments', 'theme', 'users']

/**
 * Puts the store on the screen the link names. Called once, after the catalog has loaded.
 * Returns true when something was opened.
 */
export async function applyLink(app, raw = readLink(), prompt = readPrompt()) {
  // Telegram keeps start_param for the whole visit: coming back from the admin page must not jump again.
  // One opening of the Mini App has one auth_date, so the pair marks this visit.
  const visit = `${raw}|${tg.raw?.initDataUnsafe?.auth_date || location.search}`
  try {
    if (sessionStorage.getItem('ng_link') === visit) return false
    sessionStorage.setItem('ng_link', visit)
  } catch { /* private mode */ }
  const link = parseLink(raw)
  if (prompt && !['song', 'music'].includes(link?.screen)) app.prompt = prompt
  if (!link) return false
  const { screen, arg } = link
  const home = (mode) => { app.tab = 'home'; app.stack = ['home']; if (mode) app.mode = mode }

  switch (screen) {
    case 'home':
      home(); return true
    case 'gallery':
    case 'photosets':
    case 'profile':
      app.tab = screen === 'profile' ? 'profile' : 'gallery'
      app.stack = [app.tab]
      return true
    case 'history':
      app.tab = 'profile'; app.stack = ['profile', 'history']; return true

    case 'image':
    case 'video':
    case 'tools': {
      home(screen)
      if (!arg || screen === 'tools') return true
      const m = (app.models[screen] || []).find((x) => x.id === arg)
      if (!m) return true
      if (app.isLocked(m)) {
        app.stack = ['home', 'plans']
        app.toast('Модель доступна по подписке')
      } else {
        app.modelSel[screen] = m.id
      }
      return true
    }
    case 'song':
    case 'music': {
      if (!app.models.song?.length) return false
      home('song')
      const model = app.models.song.find(item => item.id === arg)
      app.musicLink = { prompt, model: model && !app.isLocked(model) ? model.id : null }
      if (model && app.isLocked(model)) app.toast('Модель доступна по подписке')
      return true
    }
    case 'models':
      home(['image', 'video', 'song'].includes(arg) ? arg : null)
      app.openSheet('models')
      return true
    case 'more':
      home(app.mode === 'tools' ? 'image' : null)
      app.openSheet('more')
      return true
    case 'invite':
      home()
      app.openSheet('invite')
      return true

    case 'tool':
      home('tools')
      if (app.tools.some((t) => t.id === arg)) app.openSheet('tool', { tool: arg })
      return true
    case 'photoset': {
      app.tab = 'gallery'; app.stack = ['gallery']
      const id = Number(arg)
      if (app.photosets.some((p) => p.id === id)) app.openSheet('photoset', { id })
      return true
    }

    case 'topup': {
      home()
      const i = app.packs.findIndex((p) => String(p.tokens) === arg)
      if (i >= 0) app.packIndex = i
      app.stack = ['home', 'topup']
      return true
    }
    case 'plans': {
      home()
      const offers = app.subscriptionOffers
      const old = /^(.*)-(month|year)$/.exec(arg)
      const offer = offers.find((item) => String(item.id) === arg)
        || offers.find((item) => item.plan === arg)
        || (old && offers.find((item) => item.plan === old[1] && item.days === (old[2] === 'year' ? 365 : 30)))
      app.subscriptionOfferId = offer?.id ?? null
      app.stack = ['home', 'plans']
      return true
    }

    case 'result': {
      const id = Number(arg)
      if (!id) return false
      try {
        const r = await api.generation(id)
        home()
        app.openResult(r.data)
        return true
      } catch {
        home()
        app.toast('Эта работа не найдена')
        return false
      }
    }

    case 'admin': {
      if (!app.isAdmin) return false
      openAdmin(ADMIN_SECTIONS.includes(arg) ? arg : '')
      return true
    }
    default:
      return false
  }
}
