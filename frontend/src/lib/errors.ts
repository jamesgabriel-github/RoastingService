import { isAxiosError } from 'axios'

/** A short, safe message for any non-field-specific mutation failure. */
export function getGenericErrorMessage(error: unknown): string {
  if (isAxiosError(error)) {
    if (error.response?.status === 429) {
      return 'Too many attempts. Please wait a moment and try again.'
    }
    if (error.response?.status === 419) {
      return 'Your session expired. Please refresh the page and try again.'
    }
    if (error.response && error.response.status >= 500) {
      return 'Something went wrong on our end. Please try again.'
    }
  }

  return 'Something went wrong. Please try again.'
}
