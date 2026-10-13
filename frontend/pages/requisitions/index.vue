<template>
  <div class="space-y-5">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
      <div>
        <h2 class="text-xl font-bold text-slate-900">
          Store Requisitions (SR) & Purchase Requisitions (PR)
        </h2>
        <p class="text-xs text-slate-500 mt-0.5">
          SR: Request stock from a store (Pending → HOD Approved → Issued) | PR: Request new purchase (Pending → HOD Approved → GM Approved → PO Generated)
        </p>
      </div>
      <div v-if="canCreate" class="flex items-center gap-2">
        <button
          type="button"
          class="rounded-lg bg-slate-900 hover:bg-slate-800 text-white font-semibold px-4 py-2 text-sm shadow-sm"
          @click="openCreateModal('SR')"
        >
          + New Store Requisition (SR)
        </button>
        <button
          type="button"
          class="rounded-lg bg-amber-500 hover:bg-amber-600 text-slate-950 font-semibold px-4 py-2 text-sm shadow-sm"
          @click="openCreateModal('PR')"
        >
          + New Purchase Requisition (PR)
        </button>
      </div>
    </div>

    <!-- Filters Bar -->
    <div class="bg-white rounded-xl border border-slate-200 p-4 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3">
      <div class="lg:col-span-2">
        <label class="block text-xs font-medium text-slate-600 mb-1">Search</label>
        <input
          v-model="filters.search"
          type="text"
          placeholder="Search SR/PR number or notes..."
          class="w-full rounded-lg border border-slate-300 px-3 py-1.5 text-sm"
          @keyup.enter="loadRequisitions(1)"
        />
      </div>

      <div>
        <label class="block text-xs font-medium text-slate-600 mb-1">Requisition Type</label>
        <select
          v-model="filters.type"
          class="w-full rounded-lg border border-slate-300 px-3 py-1.5 text-sm bg-white"
          @change="loadRequisitions(1)"
        >
          <option value="">All Types (SR & PR)</option>
          <option value="SR">SR — Store Requisition</option>
          <option value="PR">PR — Purchase Requisition</option>
        </select>
      </div>

      <div>
        <label class="block text-xs font-medium text-slate-600 mb-1">Status</label>
        <select
          v-model="filters.status"
          class="w-full rounded-lg border border-slate-300 px-3 py-1.5 text-sm bg-white"
          @change="loadRequisitions(1)"
        >
          <option value="">All Statuses</option>
          <option value="Pending">Pending</option>
          <option value="HOD Approved">HOD Approved</option>
          <option value="GM Approved">GM Approved</option>
          <option value="Partially Issued">Partially Issued</option>
          <option value="Issued">Issued</option>
          <option value="PO Generated">PO Generated</option>
          <option value="Rejected">Rejected</option>
        </select>
      </div>

      <div class="flex items-end">
        <button
          type="button"
          class="w-full rounded-lg bg-slate-900 hover:bg-slate-800 text-white px-4 py-1.5 text-sm font-medium"
          @click="loadRequisitions(1)"
        >
          Apply Filters
        </button>
      </div>
    </div>

    <div
      v-if="loadError"
      class="rounded-lg bg-red-50 border border-red-200 p-4 text-sm text-red-700"
    >
      {{ loadError }}
    </div>

    <!-- Requisitions Table -->
    <div class="bg-white rounded-xl border border-slate-200 overflow-hidden shadow-sm">
      <div v-if="loading" class="p-8 text-center text-sm text-slate-500">
        Loading requisitions...
      </div>

      <div v-else-if="requisitions.length === 0" class="p-8 text-center text-sm text-slate-500">
        No requisitions found matching the current filters.
      </div>

      <div v-else class="overflow-x-auto">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
          <thead class="bg-slate-50 text-xs font-semibold uppercase text-slate-600">
            <tr>
              <th class="px-4 py-3 text-left">Number</th>
              <th class="px-4 py-3 text-left">Type</th>
              <th class="px-4 py-3 text-left">Department</th>
              <th class="px-4 py-3 text-left">Target Store</th>
              <th class="px-4 py-3 text-left">Requester</th>
              <th class="px-4 py-3 text-center">Items</th>
              <th class="px-4 py-3 text-left">Status</th>
              <th class="px-4 py-3 text-right">Actions</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-200">
            <tr v-for="req in requisitions" :key="req.id" class="hover:bg-slate-50">
              <td class="px-4 py-3 font-mono text-xs font-bold text-slate-900">
                <NuxtLink :to="`/requisitions/${req.id}`" class="hover:text-amber-600 underline">
                  {{ req.requisition_number }}
                </NuxtLink>
              </td>
              <td class="px-4 py-3">
                <span
                  class="inline-flex rounded px-2 py-0.5 text-xs font-bold"
                  :class="
                    req.type === 'SR'
                      ? 'bg-indigo-100 text-indigo-800'
                      : 'bg-purple-100 text-purple-800'
                  "
                >
                  {{ req.type }}
                </span>
              </td>
              <td class="px-4 py-3 text-xs text-slate-700">
                <div class="font-semibold text-slate-900">{{ req.department?.name || '—' }}</div>
                <div class="text-slate-400">{{ req.department?.code || '' }}</div>
              </td>
              <td class="px-4 py-3 text-xs text-slate-700">
                {{ req.store?.name || '—' }}
              </td>
              <td class="px-4 py-3 text-xs text-slate-700">
                <div>{{ req.requester?.name || '—' }}</div>
                <div class="text-slate-400">{{ formatDate(req.created_at) }}</div>
              </td>
              <td class="px-4 py-3 text-center font-mono text-xs font-semibold text-slate-800">
                {{ req.items?.length || 0 }}
              </td>
              <td class="px-4 py-3">
                <StatusBadge :status="req.status" />
              </td>
              <td class="px-4 py-3 text-right space-x-2">
                <NuxtLink
                  :to="`/requisitions/${req.id}`"
                  class="text-xs font-semibold text-slate-700 hover:text-slate-950 underline"
                >
                  View / Review
                </NuxtLink>
                <button
                  v-if="canEditRequisition(req)"
                  type="button"
                  class="text-xs font-semibold text-blue-600 hover:text-blue-800"
                  @click="openEditModal(req)"
                >
                  Edit
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <PaginationBar :meta="paginationMeta" @change="loadRequisitions" />
    </div>

    <!-- Create / Edit Requisition Modal -->
    <div
      v-if="showModal"
      class="fixed inset-0 z-40 flex items-center justify-center bg-slate-950/60 p-4 overflow-y-auto"
    >
      <div class="w-full max-w-3xl rounded-xl bg-white shadow-xl border border-slate-200 overflow-hidden my-8">
        <div class="px-6 py-4 bg-slate-900 text-white flex items-center justify-between">
          <h3 class="text-base font-bold">
            {{
              editingId
                ? `Edit ${form.type} Requisition`
                : form.type === 'SR'
                  ? 'New Store Requisition (SR)'
                  : 'New Purchase Requisition (PR)'
            }}
          </h3>
          <button type="button" class="text-slate-400 hover:text-white" @click="closeModal">
            ✕
          </button>
        </div>

        <form class="p-6 space-y-4 max-h-[82vh] overflow-y-auto" @submit.prevent="saveRequisition">
          <div v-if="formError" class="rounded-lg bg-red-50 border border-red-200 p-3 text-xs text-red-700">
            {{ formError }}
          </div>

          <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div>
              <label class="block text-xs font-semibold text-slate-700 mb-1">Requisition Type *</label>
              <select
                v-model="form.type"
                :disabled="Boolean(editingId)"
                class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm bg-white disabled:bg-slate-100"
              >
                <option value="SR">SR — Store Requisition</option>
                <option value="PR">PR — Purchase Requisition</option>
              </select>
            </div>

            <div>
              <label class="block text-xs font-semibold text-slate-700 mb-1">Department / Cost Center *</label>
              <select
                v-model="form.department_id"
                :disabled="Boolean(editingId) || isDepartmentScopedUser"
                required
                class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm bg-white disabled:bg-slate-100"
              >
                <option value="" disabled>Select Department</option>
                <option v-for="dept in departments" :key="dept.id" :value="dept.id">
                  {{ dept.code }} — {{ dept.name }}
                </option>
              </select>
              <p v-if="fieldErrors.department_id" class="mt-1 text-xs text-red-600">
                {{ fieldErrors.department_id[0] }}
              </p>
            </div>

            <div>
              <label class="block text-xs font-semibold text-slate-700 mb-1">
                Target Store {{ form.type === 'SR' ? '*' : '(Optional)' }}
              </label>
              <select
                v-model="form.store_id"
                :required="form.type === 'SR'"
                class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm bg-white"
              >
                <option value="">{{ form.type === 'SR' ? 'Select Store' : 'No Default Store' }}</option>
                <option v-for="st in stores" :key="st.id" :value="st.id">
                  {{ st.code }} — {{ st.name }}
                </option>
              </select>
              <p v-if="fieldErrors.store_id" class="mt-1 text-xs text-red-600">
                {{ fieldErrors.store_id[0] }}
              </p>
            </div>
          </div>

          <div>
            <label class="block text-xs font-semibold text-slate-700 mb-1">Purpose / Notes</label>
            <textarea
              v-model="form.notes"
              rows="2"
              class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
              placeholder="Operational justification or delivery notes..."
            ></textarea>
          </div>

          <!-- Line Items Section -->
          <div class="space-y-2 pt-2 border-t border-slate-200">
            <div class="flex items-center justify-between">
              <label class="text-xs font-bold uppercase tracking-wider text-slate-700">
                Requested Line Items *
              </label>
              <button
                type="button"
                class="rounded bg-slate-100 hover:bg-slate-200 px-2.5 py-1 text-xs font-semibold text-slate-800"
                @click="addLineItem"
              >
                + Add Item Row
              </button>
            </div>

            <p v-if="fieldErrors.items" class="text-xs text-red-600">
              {{ fieldErrors.items[0] }}
            </p>

            <div
              v-for="(line, idx) in form.items"
              :key="idx"
              class="grid grid-cols-1 sm:grid-cols-12 gap-2 items-start bg-slate-50 p-3 rounded-lg border border-slate-200"
            >
              <div class="sm:col-span-5">
                <label class="block text-[11px] font-medium text-slate-600 mb-0.5">Item / Product *</label>
                <select
                  v-model="line.item_id"
                  required
                  class="w-full rounded border border-slate-300 px-2.5 py-1.5 text-xs bg-white"
                >
                  <option value="" disabled>Select Active Item</option>
                  <option v-for="it in activeItems" :key="it.id" :value="it.id">
                    {{ it.sku }} — {{ it.name }} ({{ it.uom }})
                  </option>
                </select>
                <p v-if="fieldErrors[`items.${idx}.item_id`]" class="mt-0.5 text-[11px] text-red-600">
                  {{ fieldErrors[`items.${idx}.item_id`][0] }}
                </p>
              </div>

              <div class="sm:col-span-3">
                <label class="block text-[11px] font-medium text-slate-600 mb-0.5">Requested Qty *</label>
                <input
                  v-model="line.requested_quantity"
                  type="number"
                  step="0.001"
                  min="0.001"
                  required
                  class="w-full rounded border border-slate-300 px-2.5 py-1.5 text-xs bg-white"
                />
                <p v-if="fieldErrors[`items.${idx}.requested_quantity`]" class="mt-0.5 text-[11px] text-red-600">
                  {{ fieldErrors[`items.${idx}.requested_quantity`][0] }}
                </p>
              </div>

              <div class="sm:col-span-3">
                <label class="block text-[11px] font-medium text-slate-600 mb-0.5">Line Note</label>
                <input
                  v-model="line.notes"
                  type="text"
                  placeholder="Optional remark"
                  class="w-full rounded border border-slate-300 px-2.5 py-1.5 text-xs bg-white"
                />
              </div>

              <div class="sm:col-span-1 flex items-end justify-end pt-4">
                <button
                  v-if="form.items.length > 1"
                  type="button"
                  class="text-xs font-semibold text-red-600 hover:text-red-800"
                  @click="removeLineItem(idx)"
                >
                  Remove
                </button>
              </div>
            </div>
          </div>

          <div class="flex justify-end gap-2 pt-3 border-t border-slate-200">
            <button
              type="button"
              class="rounded-lg border border-slate-300 px-4 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50"
              @click="closeModal"
            >
              Cancel
            </button>
            <button
              type="submit"
              :disabled="submitting"
              class="rounded-lg bg-amber-500 hover:bg-amber-600 disabled:opacity-50 px-4 py-2 text-xs font-semibold text-slate-950"
            >
              {{ submitting ? 'Submitting...' : editingId ? 'Update Requisition' : `Submit ${form.type}` }}
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>

<script setup>
const { apiFetch, extractApiError } = useApi()
const { user, hasRole } = useAuth()
const { showToast } = useToast()

const canCreate = computed(() =>
  hasRole(['department_staff', 'hod', 'procurement_officer', 'store_manager', 'admin'])
)

const isDepartmentScopedUser = computed(() => hasRole(['department_staff', 'hod']))

const requisitions = ref([])
const departments = ref([])
const stores = ref([])
const activeItems = ref([])

const loading = ref(false)
const loadError = ref('')

const filters = reactive({
  search: '',
  type: '',
  status: ''
})

const paginationMeta = ref({
  current_page: 1,
  last_page: 1,
  total: 0,
  from: 0,
  to: 0
})

const showModal = ref(false)
const editingId = ref(null)
const submitting = ref(false)
const formError = ref('')
const fieldErrors = ref({})

const form = reactive({
  type: 'SR',
  department_id: '',
  store_id: '',
  notes: '',
  items: [{ item_id: '', requested_quantity: 1, notes: '' }]
})

const canEditRequisition = (req) => {
  if (req.status !== 'Pending') return false
  if (!user.value) return false
  if (hasRole('admin')) return true
  if (Number(req.requester_id) === Number(user.value.id)) return true
  if (hasRole('hod') && Number(req.department_id) === Number(user.value.department_id)) return true
  return false
}

const formatDate = (iso) => {
  if (!iso) return '—'
  return new Date(iso).toLocaleString()
}

const loadSupportingMasterData = async () => {
  try {
    const [deptRes, storeRes, itemRes] = await Promise.all([
      apiFetch('/departments', { query: { is_active: 1, per_page: 100 } }),
      apiFetch('/stores', { query: { is_active: 1, per_page: 100 } }),
      apiFetch('/items', { query: { is_active: 1, per_page: 100 } })
    ])
    departments.value = deptRes.data || []
    stores.value = storeRes.data || []
    activeItems.value = itemRes.data || []
  } catch (_) {
    // Handled on main load
  }
}

const loadRequisitions = async (page = 1) => {
  loading.value = true
  loadError.value = ''

  try {
    const query = { page, per_page: 10 }
    if (filters.search) query.search = filters.search
    if (filters.type) query.type = filters.type
    if (filters.status) query.status = filters.status

    const res = await apiFetch('/requisitions', { query })
    requisitions.value = res.data || []
    paginationMeta.value = {
      current_page: res.current_page || 1,
      last_page: res.last_page || 1,
      total: res.total || 0,
      from: res.from || 0,
      to: res.to || 0
    }
  } catch (err) {
    const parsed = extractApiError(err, 'Failed to load requisitions.')
    loadError.value = parsed.message
  } finally {
    loading.value = false
  }
}

const addLineItem = () => {
  form.items.push({ item_id: '', requested_quantity: 1, notes: '' })
}

const removeLineItem = (idx) => {
  if (form.items.length > 1) {
    form.items.splice(idx, 1)
  }
}

const openCreateModal = (type = 'SR') => {
  editingId.value = null
  form.type = type
  form.department_id = user.value?.department_id || (departments.value[0]?.id ?? '')
  form.store_id = type === 'SR' ? (stores.value[0]?.id ?? '') : ''
  form.notes = ''
  form.items = [{ item_id: activeItems.value[0]?.id ?? '', requested_quantity: 1, notes: '' }]
  formError.value = ''
  fieldErrors.value = {}
  showModal.value = true
}

const openEditModal = (req) => {
  editingId.value = req.id
  form.type = req.type
  form.department_id = req.department_id
  form.store_id = req.store_id || ''
  form.notes = req.notes || ''
  form.items = (req.items || []).map((li) => ({
    item_id: li.item_id,
    requested_quantity: Number(li.requested_quantity),
    notes: li.notes || ''
  }))
  if (form.items.length === 0) {
    form.items.push({ item_id: '', requested_quantity: 1, notes: '' })
  }
  formError.value = ''
  fieldErrors.value = {}
  showModal.value = true
}

const closeModal = () => {
  showModal.value = false
}

const saveRequisition = async () => {
  if (submitting.value) return
  submitting.value = true
  formError.value = ''
  fieldErrors.value = {}

  const payload = {
    type: form.type,
    department_id: Number(form.department_id),
    store_id: form.store_id ? Number(form.store_id) : null,
    notes: form.notes,
    items: form.items.map((li) => ({
      item_id: Number(li.item_id),
      requested_quantity: Number(li.requested_quantity),
      notes: li.notes || null
    }))
  }

  try {
    if (editingId.value) {
      await apiFetch(`/requisitions/${editingId.value}`, {
        method: 'PUT',
        body: payload
      })
      showToast('Requisition updated successfully.')
    } else {
      await apiFetch('/requisitions', {
        method: 'POST',
        body: payload
      })
      showToast(`${form.type} submitted successfully.`)
    }
    closeModal()
    await loadRequisitions(paginationMeta.value.current_page)
  } catch (err) {
    const parsed = extractApiError(err, 'Unable to save requisition.')
    formError.value = parsed.message
    fieldErrors.value = parsed.fieldErrors
  } finally {
    submitting.value = false
  }
}

onMounted(async () => {
  await Promise.all([loadSupportingMasterData(), loadRequisitions(1)])
})
</script>
