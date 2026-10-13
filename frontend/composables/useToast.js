export const useToast = () => {
  const toasts = useState('app_toasts', () => [])

  const showToast = (message, type = 'success', duration = 4000) => {
    const id = Date.now() + Math.random()
    toasts.value.push({ id, message, type })
    if (duration > 0 && typeof window !== 'undefined') {
      setTimeout(() => {
        dismissToast(id)
      }, duration)
    }
  }

  const dismissToast = (id) => {
    toasts.value = toasts.value.filter((t) => t.id !== id)
  }

  return {
    toasts,
    showToast,
    dismissToast
  }
}
