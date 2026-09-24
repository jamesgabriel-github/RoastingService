import axios from 'axios'

export const API_URL = import.meta.env.VITE_API_URL ?? 'http://localhost:8000'

export const api = axios.create({
  baseURL: `${API_URL}/api/v1`,
  withCredentials: true,
  withXSRFToken: true,
})

let csrfCookiePromise: Promise<void> | null = null

/**
 * Sanctum's SPA cookie auth needs a valid XSRF-TOKEN cookie before any
 * state-changing request. Call this once before login/register/logout.
 */
export function ensureCsrfCookie(): Promise<void> {
  csrfCookiePromise ??= axios
    .get(`${API_URL}/sanctum/csrf-cookie`, { withCredentials: true })
    .then(() => undefined)

  return csrfCookiePromise
}
