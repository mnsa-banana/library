const API_BASE = import.meta.env.DEV
  ? '/api/v1'
  : `${window.location.origin}/api/v1`

let _token: string | null = null
let _onUnauthorized: (() => void) | null = null

export function setApiToken(token: string | null) {
  _token = token
}

export function setOnUnauthorized(cb: () => void) {
  _onUnauthorized = cb
}

type FetchOpts = { silentOn401?: boolean }

async function apiFetch(path: string, init?: RequestInit, opts?: FetchOpts): Promise<Response> {
  const headers: Record<string, string> = {
    Accept: 'application/json',
    ...(init?.headers as Record<string, string>),
  }
  if (_token) {
    headers['Authorization'] = `Bearer ${_token}`
  }
  const res = await fetch(`${API_BASE}${path}`, { ...init, headers })
  if (res.status === 401) {
    if (!opts?.silentOn401) _onUnauthorized?.()
    throw new Error('Unauthorized')
  }
  if (!res.ok) {
    const data = await res.clone().json().catch(() => ({}))
    const fieldErrors = Object.values(data.errors || {}).flat().join(' ')
    throw new Error(fieldErrors || data.message || `API error: ${res.status}`)
  }
  return res
}

export async function apiRegister(name: string, email: string, password: string, passwordConfirmation: string) {
  const res = await fetch(`${API_BASE}/register`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: JSON.stringify({ name, email, password, password_confirmation: passwordConfirmation }),
  })
  const data = await res.json()
  if (!res.ok) {
    const fieldErrors = Object.values(data.errors || {}).flat().join(' ')
    throw new Error(fieldErrors || data.message || 'Registration failed')
  }
  return data as { user: { id: number; name: string; email: string }; token: string }
}

export async function apiLogin(email: string, password: string) {
  const res = await fetch(`${API_BASE}/login`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: JSON.stringify({ email, password }),
  })
  const data = await res.json()
  if (!res.ok) throw new Error(data.message || 'Login failed')
  return data as { user: { id: number; name: string; email: string }; token: string }
}

export async function apiLogout(token: string) {
  await fetch(`${API_BASE}/logout`, {
    method: 'POST',
    headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
  })
}

export interface BillingStatus {
  subscribed: boolean
  entitlement: {
    expires_date: string | null
    product_identifier: string
    purchase_date: string
  } | null
}

export async function fetchBillingStatus(opts?: { silentOn401?: boolean }): Promise<BillingStatus> {
  const res = await apiFetch('/billing/status', undefined, opts)
  return res.json()
}

export async function fetchCheckoutUrl(): Promise<{ checkout_url: string }> {
  const res = await apiFetch('/billing/checkout-url')
  return res.json()
}

export async function fetchMe(): Promise<{ id: number; name: string; email: string; subscribed: boolean }> {
  const res = await apiFetch('/me')
  return res.json()
}

export interface ReportSummary {
  id: number
  content_type: string
  title: string
  year: string | null
  poster_url: string | null
  certification: string | null
  summary: string | null
  published_at: string
}

export interface PaginatedResponse<T> {
  data: T[]
  meta: { current_page: number; per_page: number; total: number; last_page: number }
}

export async function fetchReports(page = 1, search?: string): Promise<PaginatedResponse<ReportSummary>> {
  const params = new URLSearchParams({ page: String(page) })
  if (search) params.set('search', search)
  const res = await apiFetch(`/reports?${params}`)
  return res.json()
}

export interface CategoryGroup {
  section_key: string
  group_key: string
  notes: string | null
}

export interface Rating {
  section_key: string
  group_key: string
  subcategory_key: string
  present: boolean | null
  level: string | null
  evidence: string
}

export interface ReportDetail extends ReportSummary {
  rating: number | null
  runtime: number | null
  overview: string | null
  directors: string | null
  creators: string | null
  top_cast: string | null
  reception: string | null
  heads_up: string | null
  is_adaptation: boolean
  source_material: string | null
  category_groups: CategoryGroup[]
  ratings: Rating[]
}

export async function fetchReport(id: number): Promise<ReportDetail> {
  const res = await apiFetch(`/reports/${id}`)
  return res.json()
}

export async function apiForgotPassword(email: string): Promise<void> {
  const res = await fetch(`${API_BASE}/password/forgot`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: JSON.stringify({ email }),
  })
  if (res.status === 429) {
    throw new Error('Too many requests. Please try again in an hour.')
  }
  if (!res.ok && res.status !== 204) {
    throw new Error('Could not send reset link. Please try again.')
  }
}

export async function apiResetPassword(body: {
  email: string
  token: string
  password: string
  password_confirmation: string
}) {
  const res = await fetch(`${API_BASE}/password/reset`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: JSON.stringify(body),
  })
  const data = await res.json()
  if (!res.ok) {
    const fieldErrors = Object.values(data.errors || {}).flat().join(' ')
    throw new Error(fieldErrors || data.message || 'Reset failed')
  }
  return data as { user: { id: number; name: string; email: string }; token: string }
}

export async function apiChangePassword(body: {
  current_password: string
  password: string
  password_confirmation: string
}): Promise<void> {
  await apiFetch('/account/password', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
  })
}

export async function apiRequestEmailChange(body: {
  current_password: string
  new_email: string
}): Promise<void> {
  await apiFetch('/account/email', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
  })
}

export async function apiConfirmEmailChange(params: { id: number; token: string }): Promise<void> {
  const res = await fetch(`${API_BASE}/account/email/confirm`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      Accept: 'application/json',
    },
    body: JSON.stringify(params),
  })
  if (!res.ok) {
    const data = await res.json().catch(() => ({}))
    const fieldErrors = Object.values(data.errors || {}).flat().join(' ')
    throw new Error(fieldErrors || data.message || 'Confirmation failed')
  }
}

export async function apiDeleteAccount(current_password: string): Promise<void> {
  await apiFetch('/account', {
    method: 'DELETE',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ current_password }),
  })
}

export async function apiManageUrl(): Promise<{ manage_url: string }> {
  const res = await apiFetch('/billing/manage-url')
  return res.json()
}
