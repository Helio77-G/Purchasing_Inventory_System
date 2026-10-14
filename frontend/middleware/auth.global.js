export default defineNuxtRouteMiddleware(async (to) => {
  const { token, user, fetchUser } = useAuth()

  // Allow access to login page
  if (to.path === '/login') {
    if (token.value) {
      if (!user.value) {
        await fetchUser()
      }
      if (user.value) {
        return navigateTo('/')
      }
    }
    return
  }

  // If no auth token, redirect to login page
  if (!token.value) {
    return navigateTo('/login')
  }

  // Populate user profile if missing
  if (!user.value) {
    const fetched = await fetchUser()
    if (!fetched) {
      return navigateTo('/login')
    }
  }
})
