<template>
  <div class="space-y-6">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
      <div>
        <h2 class="text-xl font-bold text-slate-900">
          Stock Ledger Audit Trail
        </h2>
        <p class="text-xs text-slate-500 mt-1">
          Immutable historical audit log of all posted IN, OUT, and ADJ stock transactions
        </p>
      </div>

      <NuxtLink
        to="/inventory/current-stock"
        class="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 hover:bg-slate-100 text-slate-700 px-3.5 py-2 text-xs font-semibold shadow-sm transition-colors"
      >
        <span>View Current Stock Balances &rarr;</span>
      </NuxtLink>
    </div>

    <!-- Filters Bar -->
    <div class="bg-white p-4 rounded-xl border border-slate-200 shadow-sm space-y-3">
      <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-3">
        <div class="lg:col-span-2">
          <label class="block text-xs font-semibold text-slate-600 mb-1">Search</label>
          <input
            v-model="filters.search"
            type="text"
            placeholder="Reference #, item SKU, notes..."
            class="w-full rounded-lg border border-slate-300 px-3 py-1.5 text-sm focus:border-amber-500 focus:outline-none"
            @keyup.enter="applyFilters"
          >
        </div>

        <div>
          <label class="block text-xs font-semibold text-slate-600 mb-1">Movement Type</label>
          <select
            v-model="filters.transaction_type"
            class="w-full rounded-lg border border-slate-300 px-3 py-1.5 text-sm focus:border-amber-500 focus:outline-none"
            @change="applyFilters"
          >
            <option value="">All Types (IN, OUT, ADJ)</option>
            <option value="IN">IN (Receipts)</option>
            <option value="OUT">OUT (Issues)</option>
            <option value="ADJ">ADJ (Adjustments)</option>
          </select>
        </div>

        <div>
          <label class="block text-xs font-semibold text-slate-600 mb-1">Store / Warehouse</label>
          <select
            v-model="filters.store_id"
            class="w-full rounded-lg border border-slate-300 px-3 py-1.5 text-sm focus:border-amber-500 focus:outline-none"
            @change="applyFilters"
          >
            <option value="">All Stores</option>
            <option v-for="store in stores" :key="store.id" :value="store.id">
              {{ store.name }}
            </option>
          </select>
        </div>

        <div>
          <label class="block text-xs font-semibold text-slate-600 mb-1">Date From</label>
          <input
            v-model="filters.date_from"
            type="date"
            class="w-full rounded-lg border border-slate-300 px-3 py-1.5 text-sm focus:border-amber-500 focus:outline-none"
            @change="applyFilters"
          >
        </div>

        <div>
          <label class="block text-xs font-semibold text-slate-600 mb-1">Date To</label>
          <input
            v-model="filters.date_to"
            type="date"
            class="w-full rounded-lg border border-slate-300 px-3 py-1.5 text-sm focus:border-amber-500 focus:outline-none"
            @change="applyFilters"
          >
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
        Loading stock ledger entries...
      </div>

      <div v-else-if="entries.length === 0" class="p-8 text-center text-sm text-slate-500">
        No stock ledger movements found matching criteria.
      </div>

      <div v-else class="overflow-x-auto">
        <table class="w-full text-left text-sm">
          <thead class="bg-slate-50 text-xs uppercase text-slate-500 border-b border-slate-200">
            <tr>
              <th class="px-4 py-3 font-semibold">Time</th>
              <th class="px-4 py-3 font-semibold">Reference</th>
              <th class="px-4 py-3 font-semibold">Item / SKU</th>
              <th class="px-4 py-3 font-semibold">Store</th>
              <th class="px-4 py-3 font-semibold">Cost Center</th>
              <th class="px-4 py-3 font-semibold text-center">Type</th>
              <th class="px-4 py-3 font-semibold text-right">Delta Qty</th>
              <th class="px-4 py-3 font-semibold text-right">Balance After</th>
              <th class="px-4 py-3 font-semibold">Posted By</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100 font-mono text-xs">
            <tr
              v-for="entry in entries"
              :key="entry.id"
              class="hover:bg-slate-50/80 transition-colors"
            >
              <td class="px-4 py-3 text-slate-600 font-sans whitespace-nowrap">
                {{ formatDate(entry.transaction_at) }}
              </td>
              <td class="px-4 py-3 font-bold text-slate-900 font-sans">
                <span class="block text-[10px] uppercase tracking-wider text-slate-400 font-mono">{{ entry.reference_type }}</span>
                <span>{{ entry.reference_number || '-' }}</span>
              </td>
              <td class="px-4 py-3 font-sans">
                <div class="font-bold text-slate-900">{{ entry.item?.name }}</div>
                <div class="font-mono text-[11px] text-slate-500">{{ entry.item?.sku }}</div>
              </td>
              <td class="px-4 py-3 font-sans text-slate-700">
                {{ entry.store?.name }}
              </td>
              <td class="px-4 py-3 font-sans text-slate-600">
                {{ entry.department?.name || '-' }}
              </td>
              <td class="px-4 py-3 text-center">
                <span
                  class="inline-flex rounded-full px-2 py-0.5 text-[11px] font-bold"
                  :class="typeBadgeClass(entry.transaction_type)"
                >
                  {{ entry.transaction_type }}
                </span>
              </td>
              <td
                class="px-4 py-3 text-right font-bold text-sm"
                :class="Number(entry.quantity) >= 0 ? 'text-emerald-700' : 'text-rose-700'"
              >
                {{ Number(entry.quantity) > 0 ? `+${Number(entry.quantity).toFixed(3)}` : Number(entry.quantity).toFixed(3) }}
              </td>
              <td class="px-4 py-3 text-right text-slate-900 font-bold">
                {{ Number(entry.balance_after).toFixed(3) }}
              </td>
              <td class="px-4 py-3 font-sans text-slate-600 text-xs">
                {{ entry.actor?.name || '-' }}
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <PaginationBar :pagination="pagination" @page-change="loadPage" />
    </div>
  </div>
</template>

<script setup>
const { apiFetch } = useApi()
const { showToast } = useToast()

const entries = ref([])
const stores = ref([])
const loading = ref(false)
const pagination = ref({})

const filters = ref({
  search: '',
  transaction_type: '',
  store_id: '',
  date_from: '',
  date_to: '',
  page: 1
})

const formatDate = (dateStr) => {
  if (!dateStr) return '-'
  return new Date(dateStr).toLocaleString()
}

const typeBadgeClass = (type) => {
  switch (type) {
    case 'IN':
      return 'bg-emerald-100 text-emerald-800'
    case 'OUT':
      return 'bg-rose-100 text-rose-800'
    case 'ADJ':
      return 'bg-indigo-100 text-indigo-800'
    default:
      return 'bg-slate-100 text-slate-800'
  }
}

const loadStores = async () => {
  try {
    const res = await apiFetch('/stores?per_page=100')
    stores.value = res.data || []
  } catch (e) {
    // ignore
  }
}

const fetchLedger = async () => {
  loading.value = true
  try {
    const query = new URLSearchParams()
    if (filters.value.search) query.append('search', filters.value.search)
    if (filters.value.transaction_type) query.append('transaction_type', filters.value.transaction_type)
    if (filters.value.store_id) query.append('store_id', filters.value.store_id)
    if (filters.value.date_from) query.append('date_from', filters.value.date_from)
    if (filters.value.date_to) query.append('date_to', filters.value.date_to)
    if (filters.value.page) query.append('page', filters.value.page)

    const res = await apiFetch(`/inventory/ledger?${query.toString()}`)
    entries.value = res.data || []
    pagination.value = {
      current_page: res.current_page,
      last_page: res.last_page,
      total: res.total,
      per_page: res.per_page
    }
  } catch (e) {
    showToast(e.data?.message || 'Failed to load stock ledger', 'error')
  } finally {
    loading.value = false
  }
}

const applyFilters = () => {
  filters.value.page = 1
  fetchLedger()
}

const resetFilters = () => {
  filters.value = {
    search: '',
    transaction_type: '',
    store_id: '',
    date_from: '',
    date_to: '',
    page: 1
  }
  fetchLedger()
}

const loadPage = (page) => {
  filters.value.page = page
  fetchLedger()
}

onMounted(() => {
  fetchLedger()
  loadStores()
})
</script>
