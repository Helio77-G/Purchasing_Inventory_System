export const useApi = () => {
  const config = useRuntimeConfig()
  const { token } = useAuth()

  const apiFetch = async (endpoint, options = {}) => {
    const headers = {
      Accept: 'application/json',
      ...(options.headers || {})
    }

    if (token.value) {
      headers.Authorization = `Bearer ${token.value}`
    }

    // Normalize endpoint to prevent double '/api/api/...' when baseURL already has '/api'
    let normalizedEndpoint = endpoint
    if (typeof endpoint === 'string' && config.public.apiBase) {
      const baseEndsWithApi = config.public.apiBase.replace(/\/+$/, '').endsWith('/api')
      if (baseEndsWithApi && normalizedEndpoint.startsWith('/api')) {
        normalizedEndpoint = normalizedEndpoint.replace(/^\/api(?=\/|\?|$)/, '') || '/'
      }
    }

    try {
      return await $fetch(normalizedEndpoint, {
        baseURL: config.public.apiBase,
        ...options,
        headers
      })
    } catch (error) {
      if (error?.response?.status === 401 && typeof window !== 'undefined') {
        token.value = null
        await navigateTo('/login')
      }
      throw error
    }
  }

  const extractApiError = (error, fallbackMessage = 'An unexpected error occurred.') => {
    const status = error?.response?.status || error?.statusCode || 500
    const data = error?.response?._data || error?.data || {}
    const message = data?.message || error?.message || fallbackMessage
    const fieldErrors = data?.errors || {}

    return {
      status,
      message,
      fieldErrors
    }
  }

  return {
    apiFetch,
    extractApiError
  }
}
