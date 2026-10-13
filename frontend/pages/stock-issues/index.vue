<template>
  <div class="space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
      <div>
        <h2 class="text-xl font-bold text-slate-900">
          Bulk Stock Issues (Stock OUT)
        </h2>
        <p class="text-xs text-slate-500 mt-1">
          Issue inventory from warehouse stores to requesting departments/cost centers against HOD-approved SRs
        </p>
      </div>

      <button
        v-if="canCreateIssue"
        type="button"
        class="inline-flex items-center gap-1.5 rounded-lg bg-rose-600 hover:bg-rose-700 text-white px-4 py-2 text-sm font-semibold shadow-sm transition-colors"
        @click="openCreateModal"
      >
        <span>+ Issue Stock from SR</span>
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
            placeholder="Issue #, SR #, notes..."
            class="w-full rounded-lg border border-slate-300 px-3 py-1.5 text-sm focus:border-amber-500 focus:outline-none"
            @keyup.enter="applyFilters"
          >
        </div>

        <div>
          <label class="block text-xs font-semibold text-slate-600 mb-1">Status</label>
          <select
            v-model="filters.status"
            class="w-full rounded-lg border border-slate-300 px-3 py-1.5 text-sm focus:border-amber-500 focus:outline-none"
            @change="applyFilters"
          >
            <option value="">All Statuses</option>
            <option value="Draft">Draft</option>
            <option value="Posted">Posted</option>
          </select>
        </div>

        <div>
          <label class="block text-xs font-semibold text-slate-600 mb-1">Source Store</label>
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
          <label class="block text-xs font-semibold text-slate-600 mb-1">Receiving Dept</label>
          <select
            v-model="filters.department_id"
            class="w-full rounded-lg border border-slate-300 px-3 py-1.5 text-sm focus:border-amber-500 focus:outline-none"
            @change="applyFilters"
          >
            <option value="">All Departments</option>
            <option v-for="dept in departments" :key="dept.id" :value="dept.id">
              {{ dept.name }} ({{ dept.code }})
            </option>
          </select>
        </div>
      </div>

      <div class="flex justify-end gap-2 pt-1">
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
        Loading stock issues...
      </div>

      <div v-else-if="issues.length === 0" class="p-8 text-center text-sm text-slate-500">
        No stock issue documents found matching criteria.
      </div>

      <div v-else class="overflow-x-auto">
        <table class="w-full text-left text-sm">
          <thead class="bg-slate-50 text-xs uppercase text-slate-500 border-b border-slate-200">
            <tr>
              <th class="px-4 py-3 font-semibold">Issue #</th>
              <th class="px-4 py-3 font-semibold">Source SR</th>
              <th class="px-4 py-3 font-semibold">Source Store</th>
              <th class="px-4 py-3 font-semibold">Receiving Dept</th>
              <th class="px-4 py-3 font-semibold text-center">Items</th>
              <th class="px-4 py-3 font-semibold">Status</th>
              <th class="px-4 py-3 font-semibold">Issued By / Date</th>
              <th class="px-4 py-3 font-semibold text-right">Action</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr
              v-for="issue in issues"
              :key="issue.id"
              class="hover:bg-slate-50/80 transition-colors"
            >
              <td class="px-4 py-3 font-mono font-semibold text-slate-900">
                <NuxtLink :to="`/stock-issues/${issue.id}`" class="hover:text-rose-600 underline">
                  {{ issue.issue_number }}
                </NuxtLink>
              </td>
              <td class="px-4 py-3 font-mono text-xs text-slate-700">
                <NuxtLink
                  v-if="issue.requisition"
                  :to="`/requisitions/${issue.requisition.id}`"
                  class="hover:text-amber-600 underline"
                >
                  {{ issue.requisition.requisition_number }}
                </NuxtLink>
                <span v-else>-</span>
              </td>
              <td class="px-4 py-3 text-slate-700">
                {{ issue.store?.name || '-' }}
              </td>
              <td class="px-4 py-3 text-slate-700">
                {{ issue.department?.name || '-' }}
              </td>
              <td class="px-4 py-3 text-center font-medium text-slate-700">
                {{ issue.items_count ?? '-' }}
              </td>
              <td class="px-4 py-3">
                <StatusBadge :status="issue.status" />
              </td>
              <td class="px-4 py-3 text-xs text-slate-600">
                <div v-if="issue.status === 'Posted'">
                  <div class="font-medium text-slate-800">{{ issue.issuer?.name || 'Issuer' }}</div>
                  <div class="text-[11px] text-slate-400">{{ formatDate(issue.issued_at) }}</div>
                </div>
                <div v-else>
                  <span class="text-amber-600 font-medium">Pending Post</span>
                  <div class="text-[11px] text-slate-400">Created: {{ formatDate(issue.created_at) }}</div>
                </div>
              </td>
              <td class="px-4 py-3 text-right">
                <NuxtLink
                  :to="`/stock-issues/${issue.id}`"
                  class="rounded bg-slate-100 hover:bg-slate-200 text-slate-700 px-2.5 py-1 text-xs font-semibold"
                >
                  View Details
                </NuxtLink>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <PaginationBar :pagination="pagination" @page-change="loadPage" />
    </div>

    <!-- Create Stock Issue Modal -->
    <div
      v-if="showModal"
      class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/50 p-4 overflow-y-auto"
    >
      <div class="w-full max-w-4xl bg-white rounded-2xl shadow-xl overflow-hidden my-8 max-h-[90vh] flex flex-col">
        <div class="px-6 py-4 border-b border-slate-200 flex items-center justify-between bg-slate-50 flex-shrink-0">
          <div>
            <h3 class="text-base font-bold text-slate-900">
              Issue Stock from Store Requisition (SR)
            </h3>
            <p class="text-xs text-slate-500">
              Fulfill department requisition lines and post atomic Stock OUT movements
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

        <form class="p-6 space-y-4 overflow-y-auto flex-1" @submit.prevent="submitIssue">
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label class="block text-xs font-semibold text-slate-700 mb-1">
                Select Approved Store Requisition (SR) *
              </label>
              <select
                v-model="modalForm.requisition_id"
                class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-rose-500 focus:outline-none"
                :disabled="modalLoading"
                @change="onRequisitionSelected"
              >
                <option value="">-- Choose an HOD-Approved or Partially-Issued SR --</option>
                <option v-for="sr in eligibleSrs" :key="sr.id" :value="sr.id">
                  {{ sr.requisition_number }} - {{ sr.department?.name }} ({{ sr.status }})
                </option>
              </select>
            </div>

            <div v-if="selectedSr" class="bg-slate-50 rounded-lg p-3 border border-slate-200 text-xs space-y-1">
              <div><span class="font-semibold text-slate-500">Source Store:</span> {{ selectedSr.store?.name || '-' }}</div>
              <div><span class="font-semibold text-slate-500">Receiving Dept:</span> {{ selectedSr.department?.name || '-' }}</div>
              <div><span class="font-semibold text-slate-500">SR Status:</span> <span class="font-bold text-slate-800">{{ selectedSr.status }}</span></div>
            </div>
          </div>

          <!-- Items Table -->
          <div v-if="selectedSr" class="space-y-2">
            <div class="flex items-center justify-between">
              <label class="text-xs font-semibold text-slate-700">Requisition Items & Store Availability</label>
              <span class="text-xs text-slate-400">Specify quantities to issue in this batch</span>
            </div>

            <div class="border border-slate-200 rounded-lg overflow-hidden">
              <table class="w-full text-left text-xs">
                <thead class="bg-slate-100 text-slate-600 uppercase font-semibold">
                  <tr>
                    <th class="px-3 py-2">Item / SKU</th>
                    <th class="px-3 py-2 text-right">Requested</th>
                    <th class="px-3 py-2 text-right">Already Issued</th>
                    <th class="px-3 py-2 text-right">Remaining SR</th>
                    <th class="px-3 py-2 text-right">Store Available</th>
                    <th class="px-3 py-2 text-right w-36">Issue Now Qty *</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                  <tr v-for="(line, idx) in modalForm.items" :key="line.item_id" class="hover:bg-slate-50">
                    <td class="px-3 py-2">
                      <div class="font-bold text-slate-900">{{ line.item_name }}</div>
                      <div class="text-[11px] font-mono text-slate-500">{{ line.item_sku }} ({{ line.uom }})</div>
                    </td>
                    <td class="px-3 py-2 text-right text-slate-700 font-mono">
                      {{ Number(line.requested_quantity).toFixed(3) }}
                    </td>
                    <td class="px-3 py-2 text-right text-slate-500 font-mono">
                      {{ Number(line.already_issued_quantity).toFixed(3) }}
                    </td>
                    <td class="px-3 py-2 text-right font-bold text-slate-800 font-mono">
                      {{ Number(line.remaining_quantity).toFixed(3) }}
                    </td>
                    <td class="px-3 py-2 text-right font-mono font-semibold" :class="Number(line.available_stock) >= Number(line.remaining_quantity) ? 'text-emerald-700' : 'text-rose-600'">
                      {{ Number(line.available_stock).toFixed(3) }}
                      <span v-if="Number(line.available_stock) < Number(line.remaining_quantity)" class="block text-[10px] text-rose-500">Low Stock</span>
                    </td>
                    <td class="px-3 py-2 text-right">
                      <input
                        v-model.number="line.issued_quantity"
                        type="number"
                        step="0.001"
                        min="0"
                        :max="Math.min(Number(line.remaining_quantity), Number(line.available_stock))"
                        class="w-full text-right rounded border border-slate-300 px-2 py-1 text-xs font-mono focus:border-rose-500 focus:outline-none"
                      >
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>

          <div>
            <label class="block text-xs font-semibold text-slate-700 mb-1">Issue Notes / Remarks</label>
            <textarea
              v-model="modalForm.notes"
              rows="2"
              placeholder="Optional remarks (e.g., delivered to Floor 4 Housekeeping station)"
              class="w-full rounded-lg border border-slate-300 px-3 py-1.5 text-xs focus:border-rose-500 focus:outline-none"
            />
          </div>

          <div class="flex items-center gap-2 p-3 bg-amber-50 rounded-lg border border-amber-200 text-xs text-amber-900">
            <input
              id="post_immediately"
              v-model="modalForm.post_immediately"
              type="checkbox"
              class="rounded border-amber-300 text-rose-600 focus:ring-rose-500"
            >
            <label for="post_immediately" class="font-medium cursor-pointer">
              Post Immediately (Deduct Stock OUT from store inventory and record signed ledger movements now)
            </label>
          </div>

          <div v-if="modalError" class="p-3 rounded-lg bg-red-50 border border-red-200 text-xs text-red-700">
            {{ modalError }}
          </div>

          <div class="flex justify-end gap-2 pt-2 border-t border-slate-200 flex-shrink-0">
            <button
              type="button"
              class="rounded-lg border border-slate-300 hover:bg-slate-100 text-slate-700 px-4 py-2 text-xs font-semibold"
              @click="closeCreateModal"
            >
              Cancel
            </button>
            <button
              type="submit"
              class="rounded-lg bg-rose-600 hover:bg-rose-700 text-white px-4 py-2 text-xs font-semibold disabled:opacity-50"
              :disabled="modalSubmitting || !selectedSr || modalForm.items.length === 0"
            >
              {{ modalSubmitting ? 'Saving...' : (modalForm.post_immediately ? 'Create & Post Stock OUT' : 'Save as Draft') }}
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>

<script setup>
const { apiFetch } = useApi()
const { showToast } = useToast()
const { user } = useAuth()

const issues = ref([])
const stores = ref([])
const departments = ref([])
const eligibleSrs = ref([])
const loading = ref(false)
const pagination = ref({})

const filters = ref({
  search: '',
  status: '',
  store_id: '',
  department_id: '',
  page: 1
})

const canCreateIssue = computed(() => {
  const roleSlug = user.value?.role?.slug
  return ['store_keeper', 'store_manager', 'admin'].includes(roleSlug)
})

// Modal state
const showModal = ref(false)
const modalLoading = ref(false)
const modalSubmitting = ref(false)
const modalError = ref('')
const selectedSr = ref(null)

const modalForm = ref({
  requisition_id: '',
  notes: '',
  post_immediately: true,
  items: []
})

const formatDate = (dateStr) => {
  if (!dateStr) return '-'
  return new Date(dateStr).toLocaleString()
}

const loadStores = async () => {
  try {
    const res = await apiFetch('/api/stores?per_page=100')
    stores.value = res.data || []
  } catch (e) {
    // ignore
  }
}

const loadDepartments = async () => {
  try {
    const res = await apiFetch('/api/departments?per_page=100')
    departments.value = res.data || []
  } catch (e) {
    // ignore
  }
}

const fetchIssues = async () => {
  loading.value = true
  try {
    const query = new URLSearchParams()
    if (filters.value.search) query.append('search', filters.value.search)
    if (filters.value.status) query.append('status', filters.value.status)
    if (filters.value.store_id) query.append('store_id', filters.value.store_id)
    if (filters.value.department_id) query.append('department_id', filters.value.department_id)
    if (filters.value.page) query.append('page', filters.value.page)

    const res = await apiFetch(`/api/stock-issues?${query.toString()}`)
    issues.value = res.data || []
    pagination.value = {
      current_page: res.current_page,
      last_page: res.last_page,
      total: res.total,
      per_page: res.per_page
    }
  } catch (e) {
    showToast(e.data?.message || 'Failed to load stock issues', 'error')
  } finally {
    loading.value = false
  }
}

const applyFilters = () => {
  filters.value.page = 1
  fetchIssues()
}

const resetFilters = () => {
  filters.value = {
    search: '',
    status: '',
    store_id: '',
    department_id: '',
    page: 1
  }
  fetchIssues()
}

const loadPage = (page) => {
  filters.value.page = page
  fetchIssues()
}

const openCreateModal = async () => {
  modalError.value = ''
  selectedSr.value = null
  modalForm.value = {
    requisition_id: '',
    notes: '',
    post_immediately: true,
    items: []
  }
  showModal.value = true
  modalLoading.value = true

  try {
    const res = await apiFetch('/api/requisitions?type=SR&per_page=100')
    const list = res.data || []
    eligibleSrs.value = list.filter(sr => ['HOD Approved', 'Partially Issued'].includes(sr.status))
  } catch (e) {
    showToast('Failed to load eligible store requisitions', 'error')
  } finally {
    modalLoading.value = false
  }
}

const closeCreateModal = () => {
  showModal.value = false
}

const onRequisitionSelected = async () => {
  modalError.value = ''
  if (!modalForm.value.requisition_id) {
    selectedSr.value = null
    modalForm.value.items = []
    return
  }

  modalLoading.value = true
  try {
    const res = await apiFetch(`/api/requisitions/${modalForm.value.requisition_id}`)
    const sr = res.data
    selectedSr.value = sr

    modalForm.value.items = (sr.items || []).map(item => {
      const requested = Number(item.requested_quantity) || 0
      const issued = Number(item.issued_quantity) || 0
      const remaining = Math.max(0, requested - issued)
      const available = Number(item.available_stock) || 0
      const defaultIssue = Math.min(remaining, available)

      return {
        requisition_item_id: item.id,
        item_id: item.item_id,
        item_name: item.item?.name || 'Item',
        item_sku: item.item?.sku || '',
        uom: item.uom,
        requested_quantity: requested,
        already_issued_quantity: issued,
        remaining_quantity: remaining,
        available_stock: available,
        issued_quantity: defaultIssue
      }
    }).filter(i => i.remaining_quantity > 0)
  } catch (e) {
    modalError.value = e.data?.message || 'Failed to load requisition details'
  } finally {
    modalLoading.value = false
  }
}

const submitIssue = async () => {
  modalError.value = ''
  const payloadItems = modalForm.value.items
    .filter(i => Number(i.issued_quantity) > 0)
    .map(i => ({
      item_id: i.item_id,
      issued_quantity: Number(i.issued_quantity)
    }))

  if (payloadItems.length === 0) {
    modalError.value = 'Please specify at least one positive quantity to issue.'
    return
  }

  modalSubmitting.value = true
  try {
    const res = await apiFetch('/api/stock-issues', {
      method: 'POST',
      body: {
        requisition_id: modalForm.value.requisition_id,
        notes: modalForm.value.notes,
        post_immediately: modalForm.value.post_immediately,
        items: payloadItems
      }
    })

    showToast(res.message || 'Stock Issue created successfully', 'success')
    closeCreateModal()
    fetchIssues()
  } catch (e) {
    modalError.value = e.data?.message || 'Failed to create stock issue'
  } finally {
    modalSubmitting.value = false
  }
}

onMounted(() => {
  fetchIssues()
  loadStores()
  loadDepartments()
})
</script>
