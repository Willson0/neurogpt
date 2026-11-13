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

/** Browser sign-in of an admin (Telegram Login Widget), kept between visits. */
export const webLogin = {
  key: 'ng_login',
  get() { try { return localStorage.getItem(this.key) } catch { return null } },
  set(user) { try { localStorage.setItem(this.key, btoa(unescape(encodeURIComponent(JSON.stringify(user))))) } catch { /* private mode */ } },
  clear() { try { localStorage.removeItem(this.key) } catch { /* private mode */ } },
}

function authHeader() {
  if (tg.initData) return 'tma ' + tg.initData
  const login = webLogin.get()
  if (login) return 'tgl ' + login
  const dev = import.meta.env.VITE_DEV_TELEGRAM_ID
  if (dev) return 'tma dev:' + dev
  return null
}

async function request(method, path, { json, form, query } = {}) {
  const headers = { Accept: 'application/json' }
  const auth = authHeader()
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
  const res = await fetch(BASE + path, { headers: { Authorization: authHeader() || '' } })
  if (!res.ok) throw new ApiError(res.status, null)
  return URL.createObjectURL(await res.blob())
}

const clean = (q) => Object.fromEntries(Object.entries(q || {}).filter(([, v]) => v !== '' && v !== null && v !== undefined))

export const api = {
  bootstrap: () => request('GET', '/bootstrap'),
  me: () => request('GET', '/me'),
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
  improvePrompt: (prompt, kind) => request('POST', '/prompt/improve', { json: { prompt, kind } }),
  topup: (tokens, method) => request('POST', '/payments/topup', { json: { tokens, method } }),
  buyPlan: (plan, period, method) => request('POST', '/payments/plan', { json: { plan, period, method } }),
  payment: (id) => request('GET', `/payments/${id}`),
  publicConfig: () => request('GET', '/config'),
}

/** The admin panel. Every call is checked on the server against the admin's Telegram id. */
export const adminApi = {
  stats: (q) => request('GET', '/admin/stats', { query: clean(q) }),
  settings: () => request('GET', '/admin/settings'),
  saveSettings: (values) => request('PUT', '/admin/settings', { json: { values } }),
  list: (what, q) => request('GET', `/admin/${what}`, { query: clean(q) }),
  create: (what, body) => request('POST', `/admin/${what}`, { json: body }),
  update: (what, id, body, method = 'PATCH') => request(method, `/admin/${what}/${id}`, { json: body }),
  remove: (what, id) => request('DELETE', `/admin/${what}/${id}`),
  upload: (file) => { const form = new FormData(); form.append('file', file); return request('POST', '/admin/upload', { form }) },
  input: (id) => blobUrl(`/admin/generations/${id}/input`),
  example: (photosetId, file) => { const form = new FormData(); if (file) form.append('file', file); return request('POST', `/admin/photosets/${photosetId}/example`, { form }) },
  useExample: (photosetId, generationId) => request('POST', `/admin/photosets/${photosetId}/cover`, { json: { generation_id: generationId } }),
  yookassa: () => request('GET', '/admin/yookassa'),
  saveYookassa: (body) => request('PUT', '/admin/yookassa', { json: body }),
  checkYookassa: () => request('POST', '/admin/yookassa/check'),
  syncPayment: (id) => request('POST', `/admin/payments/${id}/sync`),
  balance: (id, amount, comment) => request('POST', `/admin/users/${id}/balance`, { json: { amount, comment } }),
}
