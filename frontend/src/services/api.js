import { tg } from './telegram'

const BASE = (import.meta.env.VITE_API_URL || '') + '/api'

export class ApiError extends Error {
  constructor(status, body) {
    super(body?.message || `HTTP ${status}`)
    this.status = status
    this.body = body || {}
    this.code = body?.code
  }
}

const devAuth = import.meta.env.VITE_DEV_TELEGRAM_ID
const sessionHeaders = { 'X-NeuroGPT-Session': '1' }

export const webLogin = {
  get() { try { return localStorage.getItem('ng_login') } catch { return null } },
  clear() { try { localStorage.removeItem('ng_login') } catch { /* private mode */ } },
  async start(user) {
    const login = user ? btoa(unescape(encodeURIComponent(JSON.stringify(user)))) : this.get()
    if (!login) return
    await request('POST', '/admin/session', { login })
    this.clear()
  },
  async end() {
    await request('DELETE', '/admin/session')
    this.clear()
  },
}

function authHeader(login) {
  if (tg.initData) return 'tma ' + tg.initData
  if (login) return 'tgl ' + login
  return devAuth ? 'tma dev:' + devAuth : null
}

async function request(method, path, { json, form, query, login } = {}) {
  const headers = { Accept: 'application/json', ...sessionHeaders }
  const auth = authHeader(login)
  if (auth) headers.Authorization = auth
  let body
  if (form) body = form
  else if (json !== undefined) {
    headers['Content-Type'] = 'application/json'
    body = JSON.stringify(json)
  }
  const url = BASE + path + (query ? '?' + new URLSearchParams(query) : '')
  let res
  try {
    res = await fetch(url, { method, headers, body })
  } catch {
    throw new ApiError(0, { message: 'network', code: 'network' })
  }
  const data = await res.json().catch(() => null)
  if (!res.ok) throw new ApiError(res.status, data)
  return data
}

/** A private file (needs the Authorization header), returned as an object URL for <img>. */
async function blobUrl(path) {
  const res = await fetch(BASE + path, { headers: { ...sessionHeaders, Authorization: authHeader() || '' } })
  if (!res.ok) throw new ApiError(res.status, null)
  return URL.createObjectURL(await res.blob())
}

const clean = (q) => Object.fromEntries(Object.entries(q || {}).filter(([, v]) => v !== '' && v !== null && v !== undefined))

export const api = {
  bootstrap: () => request('GET', '/bootstrap'),
  me: () => request('GET', '/me'),
  selectModel: (kind, model) => request('PATCH', '/me/models', { json: { kind, model } }),
  transactions: () => request('GET', '/transactions'),
  dailyBonus: () => request('POST', '/bonus/daily'),
  generations: (q) => request('GET', '/generations', { query: q }),
  generation: (id) => request('GET', `/generations/${id}`),
  createGeneration: (fields, file) => {
    const form = new FormData()
    Object.entries(fields).forEach(([k, v]) => v !== undefined && v !== null && v !== '' && form.append(k, v))
    if (file) form.append('file', file)
    return request('POST', '/generations', { form })
  },
  updateGeneration: (id, patch) => request('PATCH', `/generations/${id}`, { json: patch }),
  cancelGeneration: (id) => request('POST', `/generations/${id}/cancel`),
  musicProfiles: () => request('GET', '/music/profiles'),
  createMusic: (fields, file, uploads = {}) => {
    const values = clean(fields)
    if (!file && !Object.values(uploads).some(files => files.length)) return request('POST', '/music', { json: values })
    const form = new FormData()
    for (const [key, value] of Object.entries(values)) {
      if (Array.isArray(value)) value.forEach((item) => form.append(key + '[]', item))
      else form.append(key, typeof value === 'boolean' ? Number(value) : value)
    }
    if (file) form.append('file', file)
    for (const [key, files] of Object.entries(uploads)) files.forEach(item => form.append(key + '[]', item))
    return request('POST', '/music', { form })
  },
  musicDownload: (id, track) => blobUrl(`/music/${id}/tracks/${track}/download`),
  sendTrack: (id, track) => request('POST', `/music/${id}/tracks/${track}/send`),
  improvePrompt: (prompt, kind) => request('POST', '/prompt/improve', { json: { prompt, kind } }),
  promoQuote: (kind, offer, promo) => request('POST', '/payments/promo', { json: { kind, offer_id: offer.id, promo } }),
  stopRenewal: () => request('DELETE', '/payments/renewal'),
  topup: (offer, method, extra = {}) => request('POST', '/payments/topup', {
    json: { offer_id: offer.id, tokens: offer.tokens, expected_price: offer.price, method, ...extra },
  }),
  buyPlan: (offer, method, extra = {}) => request('POST', '/payments/plan', {
    json: { offer_id: offer.id, expected_price: offer.price, expected_days: offer.days, method, ...extra },
  }),
  payment: (id) => request('GET', `/payments/${id}`),
  publicConfig: () => request('GET', '/config'),
}

/** The admin panel. Every call is checked on the server against the admin's Telegram id. */
export const adminApi = {
  stats: (q) => request('GET', '/admin/stats', { query: clean(q) }),
  settings: () => request('GET', '/admin/settings'),
  renewal: () => request('GET', '/admin/renewal'),
  saveRenewal: (values) => request('PUT', '/admin/renewal', { json: values }),
  saveSettings: (values) => request('PUT', '/admin/settings', { json: { values } }),
  list: (what, q) => request('GET', `/admin/${what}`, { query: clean(q) }),
  create: (what, body) => request('POST', `/admin/${what}`, { json: body }),
  update: (what, id, body, method = 'PATCH') => request(method, `/admin/${what}/${id}`, { json: body }),
  remove: (what, id) => request('DELETE', `/admin/${what}/${id}`),
  upload: (file, kind = 'image') => { const form = new FormData(); form.append('file', file); form.append('kind', kind); return request('POST', '/admin/upload', { form }) },
  updatePromo: (type, id, body) => request('PATCH', '/admin/promos/' + type + '/' + id, { json: body }),
  input: (id) => blobUrl(`/admin/generations/${id}/input`),
  example: (photosetId, file) => { const form = new FormData(); if (file) form.append('file', file); return request('POST', `/admin/photosets/${photosetId}/example`, { form }) },
  useExample: (photosetId, generationId) => request('POST', `/admin/photosets/${photosetId}/cover`, { json: { generation_id: generationId } }),
  yookassa: () => request('GET', '/admin/yookassa'),
  saveYookassa: (body) => request('PUT', '/admin/yookassa', { json: body }),
  checkYookassa: () => request('POST', '/admin/yookassa/check'),
  syncPayment: (id) => request('POST', `/admin/payments/${id}/sync`),
  balance: (id, amount, comment) => request('POST', `/admin/users/${id}/balance`, { json: { amount, comment } }),
}
