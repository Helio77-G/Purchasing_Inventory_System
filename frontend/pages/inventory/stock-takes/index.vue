<template>
  <div class="space-y-6">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
      <div>
        <h2 class="text-xl font-bold text-slate-900">
          Physical Stock Taking & Adjustments
        </h2>
        <p class="text-xs text-slate-500 mt-1">
          Periodic physical inventory counting, system variance review, and atomic adjustment posting
        </p>
      </div>

      <button
        v-if="canStartStockTake"
        type="button"
        class="inline-flex items-center gap-1.5 rounded-lg bg-purple-600 hover:bg-purple-700 text-white px-4 py-2 text-sm font-semibold shadow-sm transition-colors"
        @click="openCreateModal"
      >
        <span>+ Start New Stock Take</span>
      </button>
    </div>

    <!-- Filters Bar -->
    <div class="bg-white p-4 rounded-xl border border-slate-200 shadow-sm space-y-3">
      <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-3">
        <div>
          <label class="block text-xs font-semibold text-slate-600 mb-1">Search</label>
          <input
            v-model="filters.search"
            type="text"
            placeholder="Stock take #, audit reason..."
            class="w-full rounded-lg border border-slate-300 px-3 py-1.5 text-sm focus:border-purple-500 focus:outline-none"
            @keyup.enter="applyFilters"
          >
        </div>

        <div>
          <label class="block text-xs font-semibold text-slate-600 mb-1">Store / Warehouse</label>
          <select
            v-model="filters.store_id"
            class="w-full rounded-lg border border-slate-300 px-3 py-1.5 text-sm focus:border-purple-500 focus:outline-none"
            @change="applyFilters"
          >
            <option value="">All Stores</option>
            <option v-for="store in stores" :key="store.id" :value="store.id">
              {{ store.name }}
            </option>
          </select>
        </div>

        <div>
          <label class="block text-xs font-semibold text-slate-600 mb-1">Status</label>
          <select
            v-model="filters.status"
            class="w-full rounded-lg border border-slate-300 px-3 py-1.5 text-sm focus:border-purple-500 focus:outline-none"
            @change="applyFilters"
          >
            <option value="">All Statuses</option>
            <option value="Draft">Draft (Counting In Progress)</option>
            <option value="Approved">Approved (Adjustment Posted)</option>
          </select>
        </div>
      </div>

      <div class="flex justify-end gap-2 pt-1 border-t border-slate-100">
        <button
          type="button"
          class="rounded-lg border border-slate-200 hover:bg-slate-50 text-slate-600 px-3 py-1.5 text-xs font-semibold"
          @click="resetFilters"
        >
          Reset Filters
        </button>
        <button
          type="button"
          class="rounded-lg bg-slate-900 hover:bg-slate-800 text-white px-3 py-1.5 text-xs font-semibold"
          @click="applyFilters"
        >
          Apply Filters
        </button>
      </div>
    </div>

    <!-- Table -->
    <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
      <div v-if="loading" class="p-8 text-center text-sm text-slate-500">
        Loading stock take sessions...
      </div>

      <div v-else-if="stockTakes.length === 0" class="p-8 text-center text-sm text-slate-500">
        No stock taking sessions found. Click "+ Start New Stock Take" to begin an audit.
      </div>

      <div v-else class="overflow-x-auto">
        <table class="w-full text-left text-sm">
          <thead class="bg-slate-50 text-xs uppercase text-slate-500 border-b border-slate-200">
            <tr>
              <th class="px-4 py-3 font-semibold">Stock Take #</th>
              <th class="px-4 py-3 font-semibold">Store / Warehouse</th>
              <th class="px-4 py-3 font-semibold">Audit Reason</th>
              <th class="px-4 py-3 font-semibold text-center">Items</th>
              <th class="px-4 py-3 font-semibold">Status</th>
              <th class="px-4 py-3 font-semibold">Counted By / Date</th>
              <th class="px-4 py-3 font-semibold">Approved By</th>
              <th class="px-4 py-3 font-semibold text-right">Action</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr
              v-for="st in stockTakes"
              :key="st.id"
              class="hover:bg-slate-50/80 transition-colors"
            >
              <td class="px-4 py-3 font-mono font-semibold text-slate-900">
                <NuxtLink :to="`/inventory/stock-takes/${st.id}`" class="hover:text-purple-600 underline">
                  {{ st.stock_take_number }}
                </NuxtLink>
              </td>
              <td class="px-4 py-3 text-slate-700">
                {{ st.store?.name }}
              </td>
              <td class="px-4 py-3 text-slate-600 text-xs">
                {{ st.reason }}
              </td>
              <td class="px-4 py-3 text-center font-medium text-slate-700">
                {{ st.items_count ?? '-' }}
              </td>
              <td class="px-4 py-3">
                <StatusBadge :status="st.status" />
              </td>
              <td class="px-4 py-3 text-xs text-slate-600">
                <div class="font-medium text-slate-800">{{ st.counter?.name || st.creator?.name || '-' }}</div>
                <div class="text-[11px] text-slate-400">{{ formatDate(st.counted_at || st.created_at) }}</div>
              </td>
              <td class="px-4 py-3 text-xs text-slate-600">
                <div v-if="st.status === 'Approved'">
                  <div class="font-medium text-slate-800">{{ st.approver?.name || '-' }}</div>
                  <div class="text-[11px] text-slate-400">{{ formatDate(st.approved_at) }}</div>
                </div>
                <span v-else class="text-amber-600 font-medium text-xs">Awaiting Count Review</span>
              </td>
              <td class="px-4 py-3 text-right">
                <NuxtLink
                  :to="`/inventory/stock-takes/${st.id}`"
                  class="rounded bg-slate-100 hover:bg-slate-200 text-slate-700 px-2.5 py-1 text-xs font-semibold"
                >
                  {{ st.status === 'Draft' ? 'Review & Count' : 'View Audit' }}
                </NuxtLink>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <PaginationBar :pagination="pagination" @page-change="loadPage" />
    </div>

    <!-- Start New Stock Take Modal -->
    <div
      v-if="showModal"
      class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/50 p-4"
    >
      <div class="w-full max-w-lg bg-white rounded-2xl shadow-xl overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-200 flex items-center justify-between bg-slate-50">
          <div>
            <h3 class="text-base font-bold text-slate-900">
              Initiate Physical Stock Take Session
            </h3>
            <p class="text-xs text-slate-500">
              Takes an instantaneous system quantity snapshot for variance comparison
            </p>
          </div>
          <button
            type="button"
            class="text-slate-400 hover:text-slate-600 text-lg font-bold"
            @click="closeCreateModal"
          >
            &times;
          </button>
        </div>

        <form class="p-6 space-y-4" @submit.prevent="submitStockTake">
          <div>
            <label class="block text-xs font-semibold text-slate-700 mb-1">
              Select Warehouse / Store *
            </label>
            <select
              v-model="modalForm.store_id"
              required
              class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-purple-500 focus:outline-none"
            >
              <option value="">-- Choose Store to Audit --</option>
              <option v-for="store in stores" :key="store.id" :value="store.id">
                {{ store.name }} ({{ store.code }})
              </option>
            </select>
          </div>

          <div>
            <label class="block text-xs font-semibold text-slate-700 mb-1">
              Audit Reason *
            </label>
            <input
              v-model="modalForm.reason"
              type="text"
              required
              placeholder="e.g. End of Month Full Warehouse Audit"
              class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-purple-500 focus:outline-none"
            >
          </div>

          <div>
            <label class="block text-xs font-semibold text-slate-700 mb-1">
              Notes / Instructions
            </label>
            <textarea
              v-model="modalForm.notes"
              rows="2"
              placeholder="Optional notes for physical counting teams"
              class="w-full rounded-lg border border-slate-300 px-3 py-1.5 text-xs focus:border-purple-500 focus:outline-none"
            />
          </div>

          <div v-if="modalError" class="p-3 rounded-lg bg-red-50 border border-red-200 text-xs text-red-700">
            {{ modalError }}
          </div>

          <div class="flex justify-end gap-2 pt-2 border-t border-slate-200">
            <button
              type="button"
              class="rounded-lg border border-slate-300 hover:bg-slate-100 text-slate-700 px-4 py-2 text-xs font-semibold"
              @click="closeCreateModal"
            >
              Cancel
            </button>
            <button
              type="submit"
              class="rounded-lg bg-purple-600 hover:bg-purple-700 text-white px-4 py-2 text-xs font-semibold disabled:opacity-50"
              :disabled="modalSubmitting || !modalForm.store_id || !modalForm.reason"
            >
              {{ modalSubmitting ? 'Initializing...' : 'Initialize Count Session' }}
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>

<script setup>
const router = useRouter()
const { apiFetch } = useApi()
const { showToast } = useToast()
const { user } = useAuth()

const stockTakes = ref([])
const stores = ref([])
const loading = ref(false)
const pagination = ref({})

const filters = ref({
  search: '',
  store_id: '',
  status: '',
  page: 1
})

const canStartStockTake = computed(() => {
  const roleSlug = user.value?.role?.slug
  return ['store_keeper', 'store_manager', 'admin'].includes(roleSlug)
})

// Modal state
const showModal = ref(false)
const modalSubmitting = ref(false)
const modalError = ref('')

const modalForm = ref({
  store_id: '',
  reason: '',
  notes: ''
})

const formatDate = (dateStr) => {
  if (!dateStr) return '-'
  return new Date(dateStr).toLocaleString()
}

const loadStores = async () => {
  try {
    const res = await apiFetch('/stores?per_page=100')
    stores.value = res.data || []
  } catch (e) {
    // ignore
  }
}

const fetchStockTakes = async () => {
  loading.value = true
  try {
    const query = new URLSearchParams()
    if (filters.value.search) query.append('search', filters.value.search)
    if (filters.value.store_id) query.append('store_id', filters.value.store_id)
    if (filters.value.status) query.append('status', filters.value.status)
    if (filters.value.page) query.append('page', filters.value.page)

    const res = await apiFetch(`/inventory/stock-takes?${query.toString()}`)
    stockTakes.value = res.data || []
    pagination.value = {
      current_page: res.current_page,
      last_page: res.last_page,
      total: res.total,
      per_page: res.per_page
    }
  } catch (e) {
    showToast(e.data?.message || 'Failed to load stock takes', 'error')
  } finally {
    loading.value = false
  }
}

const applyFilters = () => {
  filters.value.page = 1
  fetchStockTakes()
}

const resetFilters = () => {
  filters.value = {
    search: '',
    store_id: '',
    status: '',
    page: 1
  }
  fetchStockTakes()
}

const loadPage = (page) => {
  filters.value.page = page
  fetchStockTakes()
}

const openCreateModal = () => {
  modalError.value = ''
  modalForm.value = {
    store_id: stores.value.length ? stores.value[0].id : '',
    reason: 'Monthly physical inventory audit',
    notes: ''
  }
  showModal.value = true
}

const closeCreateModal = () => {
  showModal.value = false
}

const submitStockTake = async () => {
  modalError.value = ''
  modalSubmitting.value = true
  try {
    const res = await apiFetch('/inventory/stock-takes', {
      method: 'POST',
      body: modalForm.value
    })

    showToast('Stock-taking session initialized successfully', 'success')
    closeCreateModal()
    router.push(`/inventory/stock-takes/${res.data.id}`)
  } catch (e) {
    modalError.value = e.data?.message || 'Failed to initialize stock take'
  } finally {
    modalSubmitting.value = false
  }
}

onMounted(() => {
  fetchStockTakes()
  loadStores()
})
</script>
