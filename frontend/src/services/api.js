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

function authHeader() {
  if (tg.initData) return 'tma ' + tg.initData
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
  } catch (e) {
    throw new ApiError(0, { message: 'network', code: 'network' })
  }
  const data = await res.json().catch(() => null)
  if (!res.ok) throw new ApiError(res.status, data)
  return data
}

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
  sendMessage: (payload) => request('POST', '/chats/messages', { json: payload }),
  chat: (id) => request('GET', `/chats/${id}`),
  chats: () => request('GET', '/chats'),
  topup: (tokens) => request('POST', '/payments/topup', { json: { tokens } }),
  buyPlan: (plan, period) => request('POST', '/payments/plan', { json: { plan, period } }),
  payment: (id) => request('GET', `/payments/${id}`),
}
