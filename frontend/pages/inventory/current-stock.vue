<template>
  <div class="space-y-6">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
      <div>
        <h2 class="text-xl font-bold text-slate-900">
          Current Stock & Warehouse Balances
        </h2>
        <p class="text-xs text-slate-500 mt-1">
          Real-time stock balance snapshots across hotel stores and par level monitoring
        </p>
      </div>

      <div class="flex items-center gap-2">
        <button
          type="button"
          class="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 hover:bg-slate-100 text-slate-700 px-3.5 py-2 text-xs font-semibold shadow-sm transition-colors"
          :disabled="reconciling"
          @click="checkReconciliation"
        >
          <span>{{ reconciling ? 'Verifying...' : '⚡ Verify Ledger Reconciliation' }}</span>
        </button>

        <NuxtLink
          to="/inventory/stock-takes"
          class="inline-flex items-center gap-1.5 rounded-lg bg-slate-900 hover:bg-slate-800 text-white px-3.5 py-2 text-xs font-semibold shadow-sm transition-colors"
        >
          <span>Stock Taking</span>
        </NuxtLink>
      </div>
    </div>

    <!-- Reconciliation Banner -->
    <div
      v-if="reconciliationReport"
      class="rounded-xl p-4 border text-xs flex items-center justify-between transition-all"
      :class="reconciliationReport.reconciled ? 'bg-emerald-50 border-emerald-200 text-emerald-900' : 'bg-red-50 border-red-200 text-red-900'"
    >
      <div class="flex items-center gap-3">
        <span class="text-xl">{{ reconciliationReport.reconciled ? '✅' : '⚠️' }}</span>
        <div>
          <div class="font-bold text-sm">
            {{ reconciliationReport.reconciled ? 'Authoritative Inventory Reconciled' : 'Discrepancy Detected in Warehouse Stock' }}
          </div>
          <div class="text-[11px] opacity-90 mt-0.5">
            {{ reconciliationReport.reconciled
              ? `All ${reconciliationReport.total_checked} store-item balance records reconcile 100% to signed Stock Ledger transactions.`
              : `${reconciliationReport.discrepancies_count} store-item pair(s) have discrepancy between current stock and ledger sum.` }}
          </div>
        </div>
      </div>
      <button
        type="button"
        class="text-xs font-bold underline opacity-75 hover:opacity-100"
        @click="reconciliationReport = null"
      >
        Dismiss
      </button>
    </div>

    <!-- Filters Bar -->
    <div class="bg-white p-4 rounded-xl border border-slate-200 shadow-sm space-y-3">
      <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-3">
        <div>
          <label class="block text-xs font-semibold text-slate-600 mb-1">Search</label>
          <input
            v-model="filters.search"
            type="text"
            placeholder="SKU, item name, store..."
            class="w-full rounded-lg border border-slate-300 px-3 py-1.5 text-sm focus:border-amber-500 focus:outline-none"
            @keyup.enter="applyFilters"
          >
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
              {{ store.name }} ({{ store.code }})
            </option>
          </select>
        </div>

        <div>
          <label class="block text-xs font-semibold text-slate-600 mb-1">Category</label>
          <select
            v-model="filters.category"
            class="w-full rounded-lg border border-slate-300 px-3 py-1.5 text-sm focus:border-amber-500 focus:outline-none"
            @change="applyFilters"
          >
            <option value="">All Categories</option>
            <option v-for="cat in categories" :key="cat" :value="cat">
              {{ cat }}
            </option>
          </select>
        </div>

        <div class="flex items-center pt-5">
          <label class="flex items-center gap-2 cursor-pointer text-xs font-semibold text-slate-700">
            <input
              v-model="filters.below_par"
              type="checkbox"
              class="rounded border-slate-300 text-rose-600 focus:ring-rose-500"
              @change="applyFilters"
            >
            <span :class="filters.below_par ? 'text-rose-700 font-bold' : ''">Show Below Par Level Only</span>
          </label>
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
        Loading inventory balances...
      </div>

      <div v-else-if="stocks.length === 0" class="p-8 text-center text-sm text-slate-500">
        No stock balance records found matching criteria.
      </div>

      <div v-else class="overflow-x-auto">
        <table class="w-full text-left text-sm">
          <thead class="bg-slate-50 text-xs uppercase text-slate-500 border-b border-slate-200">
            <tr>
              <th class="px-4 py-3 font-semibold">Item / SKU</th>
              <th class="px-4 py-3 font-semibold">Category</th>
              <th class="px-4 py-3 font-semibold">Store</th>
              <th class="px-4 py-3 font-semibold text-right">Current Balance</th>
              <th class="px-4 py-3 font-semibold text-right">Min Par Level</th>
              <th class="px-4 py-3 font-semibold text-center">Status</th>
              <th class="px-4 py-3 font-semibold text-right">Unit Cost</th>
              <th class="px-4 py-3 font-semibold text-right">Total Valuation</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr
              v-for="row in stocks"
              :key="row.id"
              class="hover:bg-slate-50/80 transition-colors"
              :class="isBelowPar(row) ? 'bg-rose-50/30' : ''"
            >
              <td class="px-4 py-3">
                <div class="font-bold text-slate-900">{{ row.item?.name }}</div>
                <div class="font-mono text-xs text-slate-500">{{ row.item?.sku }} ({{ row.item?.uom }})</div>
              </td>
              <td class="px-4 py-3 text-xs text-slate-600">
                {{ row.item?.category }}
              </td>
              <td class="px-4 py-3 text-xs text-slate-700 font-medium">
                {{ row.store?.name }}
                <span class="text-[10px] text-slate-400 block font-mono">{{ row.store?.code }}</span>
              </td>
              <td class="px-4 py-3 text-right font-mono font-bold text-sm" :class="isBelowPar(row) ? 'text-rose-600' : 'text-slate-900'">
                {{ Number(row.quantity).toFixed(3) }}
              </td>
              <td class="px-4 py-3 text-right font-mono text-xs text-slate-600">
                {{ Number(row.item?.min_par_level || 0).toFixed(3) }}
              </td>
              <td class="px-4 py-3 text-center">
                <span
                  v-if="isBelowPar(row)"
                  class="inline-flex items-center gap-1 rounded-full bg-rose-100 text-rose-800 px-2.5 py-0.5 text-xs font-semibold"
                >
                  ⚠️ Below Par
                </span>
                <span
                  v-else
                  class="inline-flex items-center rounded-full bg-emerald-100 text-emerald-800 px-2.5 py-0.5 text-xs font-semibold"
                >
                  Adequate
                </span>
              </td>
              <td class="px-4 py-3 text-right font-mono text-xs text-slate-600">
                {{ formatCurrency(row.item?.unit_cost) }}
              </td>
              <td class="px-4 py-3 text-right font-mono font-bold text-xs text-slate-900">
                {{ formatCurrency(Number(row.quantity) * Number(row.item?.unit_cost || 0)) }}
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

const stocks = ref([])
const stores = ref([])
const categories = ref([])
const loading = ref(false)
const reconciling = ref(false)
const reconciliationReport = ref(null)
const pagination = ref({})

const filters = ref({
  search: '',
  store_id: '',
  category: '',
  below_par: false,
  page: 1
})

const isBelowPar = (row) => {
  const qty = Number(row.quantity) || 0
  const par = Number(row.item?.min_par_level) || 0
  return qty < par
}

const formatCurrency = (val) => {
  const num = Number(val) || 0
  return num.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

const loadStores = async () => {
  try {
    const res = await apiFetch('/stores?per_page=100')
    stores.value = res.data || []
  } catch (e) {
    // ignore
  }
}

const loadCategories = async () => {
  try {
    const res = await apiFetch('/items?per_page=100')
    const cats = new Set((res.data || []).map(i => i.category).filter(Boolean))
    categories.value = Array.from(cats)
  } catch (e) {
    // ignore
  }
}

const fetchCurrentStock = async () => {
  loading.value = true
  try {
    const query = new URLSearchParams()
    if (filters.value.search) query.append('search', filters.value.search)
    if (filters.value.store_id) query.append('store_id', filters.value.store_id)
    if (filters.value.category) query.append('category', filters.value.category)
    if (filters.value.below_par) query.append('below_par', '1')
    if (filters.value.page) query.append('page', filters.value.page)

    const res = await apiFetch(`/inventory/current-stock?${query.toString()}`)
    stocks.value = res.data || []
    pagination.value = {
      current_page: res.current_page,
      last_page: res.last_page,
      total: res.total,
      per_page: res.per_page
    }
  } catch (e) {
    showToast(e.data?.message || 'Failed to load current stock', 'error')
  } finally {
    loading.value = false
  }
}

const applyFilters = () => {
  filters.value.page = 1
  fetchCurrentStock()
}

const resetFilters = () => {
  filters.value = {
    search: '',
    store_id: '',
    category: '',
    below_par: false,
    page: 1
  }
  fetchCurrentStock()
}

const loadPage = (page) => {
  filters.value.page = page
  fetchCurrentStock()
}

const checkReconciliation = async () => {
  reconciling.value = true
  try {
    const query = filters.value.store_id ? `?store_id=${filters.value.store_id}` : ''
    const res = await apiFetch(`/inventory/reconciliation${query}`)
    reconciliationReport.value = res.data
    if (res.data?.reconciled) {
      showToast('All store stocks reconcile to stock ledger audit trail!', 'success')
    } else {
      showToast('Warning: Discrepancy detected during stock reconciliation.', 'error')
    }
  } catch (e) {
    showToast('Failed to run stock reconciliation', 'error')
  } finally {
    reconciling.value = false
  }
}

onMounted(() => {
  fetchCurrentStock()
  loadStores()
  loadCategories()
})
</script>
