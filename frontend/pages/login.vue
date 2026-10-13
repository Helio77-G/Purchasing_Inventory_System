<template>
  <div class="min-h-screen bg-slate-900 flex items-center justify-center p-4">
    <div class="w-full max-w-md bg-white rounded-xl shadow-2xl border border-slate-200 overflow-hidden">
      <div class="bg-slate-950 px-6 py-5 text-white">
        <div class="text-xs font-semibold uppercase tracking-wider text-amber-400">
          Hotel Back-Office
        </div>
        <h1 class="text-xl font-bold mt-1">
          Purchasing & Inventory System
        </h1>
        <p class="text-xs text-slate-400 mt-1">
          Sign in with your authorized staff credentials
        </p>
      </div>

      <form class="p-6 space-y-4" @submit.prevent="handleLogin">
        <div
          v-if="errorMessage"
          class="rounded-lg bg-red-50 border border-red-200 px-4 py-3 text-xs text-red-700"
        >
          {{ errorMessage }}
        </div>

        <div>
          <label class="block text-xs font-semibold text-slate-700 mb-1">
            Email Address
          </label>
          <input
            v-model="form.email"
            type="email"
            required
            class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-amber-500 focus:outline-none"
            placeholder="admin@hotel.local"
          />
          <p v-if="fieldErrors.email" class="mt-1 text-xs text-red-600">
            {{ fieldErrors.email[0] }}
          </p>
        </div>

        <div>
          <label class="block text-xs font-semibold text-slate-700 mb-1">
            Password
          </label>
          <input
            v-model="form.password"
            type="password"
            required
            class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-amber-500 focus:outline-none"
            placeholder="••••••••"
          />
          <p v-if="fieldErrors.password" class="mt-1 text-xs text-red-600">
            {{ fieldErrors.password[0] }}
          </p>
        </div>

        <button
          type="submit"
          :disabled="submitting"
          class="w-full rounded-lg bg-amber-500 hover:bg-amber-600 disabled:opacity-50 text-slate-950 font-semibold py-2.5 text-sm transition-colors"
        >
          {{ submitting ? 'Signing in...' : 'Sign In' }}
        </button>

        <div class="pt-3 border-t border-slate-200">
          <div class="text-xs font-semibold text-slate-500 mb-2">
            Quick Demo Accounts (Password: <code class="text-slate-700">password123</code>):
          </div>
          <div class="grid grid-cols-2 gap-1.5 text-xs">
            <button
              v-for="acct in demoAccounts"
              :key="acct.email"
              type="button"
              class="rounded border border-slate-200 hover:bg-slate-50 px-2.5 py-1.5 text-left text-slate-700"
              @click="fillDemo(acct.email)"
            >
              <div class="font-semibold">{{ acct.label }}</div>
              <div class="text-[11px] text-slate-500 truncate">{{ acct.email }}</div>
            </button>
          </div>
        </div>
      </form>
    </div>
  </div>
</template>

<script setup>
definePageMeta({
  layout: false
})

const { login } = useAuth()
const { extractApiError } = useApi()
const { showToast } = useToast()

const form = reactive({
  email: 'admin@hotel.local',
  password: 'password123'
})

const submitting = ref(false)
const errorMessage = ref('')
const fieldErrors = ref({})

const demoAccounts = [
  { label: 'System Admin', email: 'admin@hotel.local' },
  { label: 'Procurement', email: 'procurement@hotel.local' },
  { label: 'Store Manager', email: 'store.manager@hotel.local' },
  { label: 'Housekeeping HOD', email: 'hod.hk@hotel.local' },
  { label: 'General Manager', email: 'gm@hotel.local' },
  { label: 'HK Staff', email: 'staff.hk@hotel.local' }
]

const fillDemo = (email) => {
  form.email = email
  form.password = 'password123'
}

const handleLogin = async () => {
  if (submitting.value) return
  submitting.value = true
  errorMessage.value = ''
  fieldErrors.value = {}

  try {
    await login(form.email, form.password)
    showToast('Signed in successfully.')
    await navigateTo('/')
  } catch (err) {
    const parsed = extractApiError(err, 'Unable to sign in.')
    errorMessage.value = parsed.message
    fieldErrors.value = parsed.fieldErrors
  } finally {
    submitting.value = false
  }
}
</script>
