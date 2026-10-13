export const useAuth = () => {
  const token = useCookie('hotel_inv_token', {
    maxAge: 60 * 60 * 24 * 7,
    sameSite: 'lax'
  })
  const user = useState('hotel_inv_user', () => null)
  const config = useRuntimeConfig()

  const isAuthenticated = computed(() => Boolean(token.value))

  const hasRole = (roles) => {
    if (!user.value || !user.value.role) return false
    const allowed = Array.isArray(roles) ? roles : [roles]
    return allowed.includes(user.value.role.slug)
  }

  const login = async (email, password) => {
    const response = await $fetch('/auth/login', {
      baseURL: config.public.apiBase,
      method: 'POST',
      body: { email, password }
    })

    token.value = response?.data?.token || null
    user.value = response?.data?.user || null
    return response
  }

  const fetchUser = async () => {
    if (!token.value) {
      user.value = null
      return null
    }

    try {
      const response = await $fetch('/auth/me', {
        baseURL: config.public.apiBase,
        headers: {
          Accept: 'application/json',
          Authorization: `Bearer ${token.value}`
        }
      })
      user.value = response?.data || null
      return user.value
    } catch (err) {
      if (err?.response?.status === 401) {
        token.value = null
        user.value = null
      }
      return null
    }
  }

  const logout = async () => {
    if (token.value) {
      try {
        await $fetch('/auth/logout', {
          baseURL: config.public.apiBase,
          method: 'POST',
          headers: {
            Accept: 'application/json',
            Authorization: `Bearer ${token.value}`
          }
        })
      } catch (_) {
        // Ignore logout network error and clear local state
      }
    }
    token.value = null
    user.value = null
    await navigateTo('/login')
  }

  return {
    token,
    user,
    isAuthenticated,
    hasRole,
    login,
    fetchUser,
    logout
  }
}
