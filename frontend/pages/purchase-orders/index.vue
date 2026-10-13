<template>
  <div class="space-y-5">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
      <div>
        <h2 class="text-xl font-bold text-slate-900">Purchase Orders (PO)</h2>
        <p class="text-xs text-slate-500 mt-0.5">
          Convert GM-approved PRs into Supplier POs | Workflow: Draft → Sent → Partial Received → Fully Received
        </p>
      </div>
      <button
        v-if="canManagePo"
        type="button"
        class="rounded-lg bg-amber-500 hover:bg-amber-600 text-slate-950 font-semibold px-4 py-2 text-sm shadow-sm"
        @click="openCreateModal"
      >
        + Create PO from Approved PR
      </button>
    </div>

    <!-- Filters Bar -->
    <div class="bg-white rounded-xl border border-slate-200 p-4 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3">
      <div class="lg:col-span-2">
        <label class="block text-xs font-medium text-slate-600 mb-1">Search</label>
        <input
          v-model="filters.search"
          type="text"
          placeholder="Search PO number or notes..."
          class="w-full rounded-lg border border-slate-300 px-3 py-1.5 text-sm"
          @keyup.enter="loadPurchaseOrders(1)"
        />
      </div>

      <div>
        <label class="block text-xs font-medium text-slate-600 mb-1">Status</label>
        <select
          v-model="filters.status"
          class="w-full rounded-lg border border-slate-300 px-3 py-1.5 text-sm bg-white"
          @change="loadPurchaseOrders(1)"
        >
          <option value="">All Statuses</option>
          <option value="Draft">Draft</option>
          <option value="Sent">Sent</option>
          <option value="Partial Received">Partial Received</option>
          <option value="Fully Received">Fully Received</option>
          <option value="Cancelled">Cancelled</option>
        </select>
      </div>

      <div>
        <label class="block text-xs font-medium text-slate-600 mb-1">Supplier</label>
        <select
          v-model="filters.supplier_id"
          class="w-full rounded-lg border border-slate-300 px-3 py-1.5 text-sm bg-white"
          @change="loadPurchaseOrders(1)"
        >
          <option value="">All Suppliers</option>
          <option v-for="sup in suppliers" :key="sup.id" :value="sup.id">
            {{ sup.code }} — {{ sup.name }}
          </option>
        </select>
      </div>

      <div class="flex items-end">
        <button
          type="button"
          class="w-full rounded-lg bg-slate-900 hover:bg-slate-800 text-white px-4 py-1.5 text-sm font-medium"
          @click="loadPurchaseOrders(1)"
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

    <!-- PO Table -->
    <div class="bg-white rounded-xl border border-slate-200 overflow-hidden shadow-sm">
      <div v-if="loading" class="p-8 text-center text-sm text-slate-500">
        Loading purchase orders...
      </div>

      <div v-else-if="purchaseOrders.length === 0" class="p-8 text-center text-sm text-slate-500">
        No purchase orders found matching the current filters.
      </div>

      <div v-else class="overflow-x-auto">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
          <thead class="bg-slate-50 text-xs font-semibold uppercase text-slate-600">
            <tr>
              <th class="px-4 py-3 text-left">PO Number</th>
              <th class="px-4 py-3 text-left">PR Source</th>
              <th class="px-4 py-3 text-left">Supplier</th>
              <th class="px-4 py-3 text-center">Items</th>
              <th class="px-4 py-3 text-right">Total Amount</th>
              <th class="px-4 py-3 text-left">Status</th>
              <th class="px-4 py-3 text-right">Actions</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-200">
            <tr v-for="po in purchaseOrders" :key="po.id" class="hover:bg-slate-50">
              <td class="px-4 py-3 font-mono text-xs font-bold text-slate-900">
                <NuxtLink :to="`/purchase-orders/${po.id}`" class="hover:text-amber-600 underline">
                  {{ po.po_number }}
                </NuxtLink>
              </td>
              <td class="px-4 py-3 text-xs text-slate-700">
                <NuxtLink
                  v-if="po.requisition"
                  :to="`/requisitions/${po.requisition.id}`"
                  class="font-mono font-semibold text-indigo-700 hover:underline"
                >
                  {{ po.requisition.requisition_number }}
                </NuxtLink>
                <span v-else>—</span>
                <div class="text-slate-400">{{ po.requisition?.department?.name || '' }}</div>
              </td>
              <td class="px-4 py-3 text-xs text-slate-800">
                <div class="font-semibold">{{ po.supplier?.name || '—' }}</div>
                <div class="text-slate-400">{{ po.supplier?.code || '' }}</div>
              </td>
              <td class="px-4 py-3 text-center font-mono text-xs font-semibold text-slate-800">
                {{ po.items?.length || 0 }}
              </td>
              <td class="px-4 py-3 text-right font-mono text-xs font-bold text-slate-900">
                {{ formatCurrency(po.total_amount) }}
              </td>
              <td class="px-4 py-3">
                <StatusBadge :status="po.status" />
              </td>
              <td class="px-4 py-3 text-right space-x-2">
                <NuxtLink
                  :to="`/purchase-orders/${po.id}`"
                  class="text-xs font-semibold text-slate-700 hover:text-slate-950 underline"
                >
                  View
                </NuxtLink>
                <button
                  v-if="canManagePo && po.status === 'Draft'"
                  type="button"
                  class="text-xs font-semibold text-blue-600 hover:text-blue-800"
                  @click="openEditModal(po)"
                >
                  Edit
                </button>
                <button
                  v-if="canManagePo && po.status === 'Draft'"
                  type="button"
                  :disabled="busyId === po.id"
                  class="text-xs font-semibold text-emerald-600 hover:text-emerald-800 disabled:opacity-50"
                  @click="handleSendPo(po)"
                >
                  Send PO
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <PaginationBar :meta="paginationMeta" @change="loadPurchaseOrders" />
    </div>

    <!-- Create / Edit PO Modal -->
    <div
      v-if="showModal"
      class="fixed inset-0 z-40 flex items-center justify-center bg-slate-950/60 p-4 overflow-y-auto"
    >
      <div class="w-full max-w-4xl rounded-xl bg-white shadow-xl border border-slate-200 overflow-hidden my-8">
        <div class="px-6 py-4 bg-slate-900 text-white flex items-center justify-between">
          <h3 class="text-base font-bold">
            {{ editingId ? 'Edit Draft Purchase Order' : 'Create Purchase Order from GM-Approved PR' }}
          </h3>
          <button type="button" class="text-slate-400 hover:text-white" @click="closeModal">
            ✕
          </button>
        </div>

        <form class="p-6 space-y-4 max-h-[82vh] overflow-y-auto" @submit.prevent="savePurchaseOrder">
          <div v-if="formError" class="rounded-lg bg-red-50 border border-red-200 p-3 text-xs text-red-700">
            {{ formError }}
          </div>

          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label class="block text-xs font-semibold text-slate-700 mb-1">
                Originating GM-Approved PR *
              </label>
              <select
                v-model="form.requisition_id"
                :disabled="Boolean(editingId)"
                required
                class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm bg-white disabled:bg-slate-100"
                @change="onSelectPr"
              >
                <option value="" disabled>Select GM-Approved Purchase Requisition</option>
                <option v-for="pr in eligiblePrs" :key="pr.id" :value="pr.id">
                  {{ pr.requisition_number }} — {{ pr.department?.name }} ({{ pr.items?.length || 0 }} items)
                </option>
              </select>
              <p v-if="fieldErrors.requisition_id" class="mt-1 text-xs text-red-600">
                {{ fieldErrors.requisition_id[0] }}
              </p>
            </div>

            <div>
              <label class="block text-xs font-semibold text-slate-700 mb-1">Supplier *</label>
              <select
                v-model="form.supplier_id"
                required
                class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm bg-white"
              >
                <option value="" disabled>Select Active Supplier</option>
                <option v-for="sup in suppliers" :key="sup.id" :value="sup.id">
                  {{ sup.code }} — {{ sup.name }}
                </option>
              </select>
              <p v-if="fieldErrors.supplier_id" class="mt-1 text-xs text-red-600">
                {{ fieldErrors.supplier_id[0] }}
              </p>
            </div>
          </div>

          <div>
            <label class="block text-xs font-semibold text-slate-700 mb-1">PO Terms / Delivery Notes</label>
            <textarea
              v-model="form.notes"
              rows="2"
              class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
              placeholder="Payment terms, delivery schedule, or supplier instructions..."
            ></textarea>
          </div>

          <!-- PO Line Items & Pricing -->
          <div class="space-y-2 pt-2 border-t border-slate-200">
            <div class="flex items-center justify-between">
              <label class="text-xs font-bold uppercase tracking-wider text-slate-700">
                PO Line Items & Unit Pricing *
              </label>
              <span class="text-xs text-slate-500">
                Server recalculates authoritative line totals and grand total
              </span>
            </div>

            <div
              v-if="form.items.length === 0"
              class="rounded-lg bg-slate-50 border border-slate-200 p-4 text-center text-xs text-slate-500"
            >
              Select an eligible GM-Approved PR above to populate its requested items.
            </div>

            <div
              v-for="(line, idx) in form.items"
              :key="idx"
              class="grid grid-cols-1 sm:grid-cols-12 gap-2 items-center bg-slate-50 p-3 rounded-lg border border-slate-200 text-xs"
            >
              <div class="sm:col-span-4">
                <div class="font-semibold text-slate-900">{{ line.item_label }}</div>
                <div class="text-slate-500 font-mono">UoM: {{ line.uom }}</div>
              </div>

              <div class="sm:col-span-3">
                <label class="block text-[11px] font-medium text-slate-600 mb-0.5">Ordered Qty *</label>
                <input
                  v-model="line.ordered_quantity"
                  type="number"
                  step="0.001"
                  min="0.001"
                  required
                  class="w-full rounded border border-slate-300 px-2.5 py-1.5 text-xs bg-white"
                />
                <p v-if="fieldErrors[`items.${idx}.ordered_quantity`]" class="mt-0.5 text-[11px] text-red-600">
                  {{ fieldErrors[`items.${idx}.ordered_quantity`][0] }}
                </p>
              </div>

              <div class="sm:col-span-3">
                <label class="block text-[11px] font-medium text-slate-600 mb-0.5">Unit Price *</label>
                <input
                  v-model="line.unit_price"
                  type="number"
                  step="0.0001"
                  min="0.0001"
                  required
                  class="w-full rounded border border-slate-300 px-2.5 py-1.5 text-xs bg-white"
                />
                <p v-if="fieldErrors[`items.${idx}.unit_price`]" class="mt-0.5 text-[11px] text-red-600">
                  {{ fieldErrors[`items.${idx}.unit_price`][0] }}
                </p>
              </div>

              <div class="sm:col-span-2 text-right">
                <div class="text-[11px] text-slate-500">Est. Line Total</div>
                <div class="font-mono font-bold text-slate-900">
                  {{ formatCurrency((Number(line.ordered_quantity) || 0) * (Number(line.unit_price) || 0)) }}
                </div>
              </div>
            </div>

            <div v-if="form.items.length > 0" class="flex justify-end pt-2 text-sm font-bold text-slate-900">
              Estimated Grand Total:
              <span class="ml-2 font-mono text-amber-700">{{ formatCurrency(estimatedGrandTotal) }}</span>
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
              :disabled="submitting || form.items.length === 0"
              class="rounded-lg bg-amber-500 hover:bg-amber-600 disabled:opacity-50 px-4 py-2 text-xs font-semibold text-slate-950"
            >
              {{ submitting ? 'Saving...' : editingId ? 'Update Draft PO' : 'Save Draft PO' }}
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>

<script setup>
const { apiFetch, extractApiError } = useApi()
const { hasRole } = useAuth()
const { showToast } = useToast()

const canManagePo = computed(() => hasRole(['procurement_officer', 'admin']))

const purchaseOrders = ref([])
const suppliers = ref([])
const eligiblePrs = ref([])

const loading = ref(false)
const loadError = ref('')
const busyId = ref(null)

const filters = reactive({
  search: '',
  status: '',
  supplier_id: ''
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
  requisition_id: '',
  supplier_id: '',
  notes: '',
  items: []
})

const estimatedGrandTotal = computed(() =>
  form.items.reduce(
    (sum, li) => sum + (Number(li.ordered_quantity) || 0) * (Number(li.unit_price) || 0),
    0
  )
)

const formatCurrency = (val) => {
  const num = Number(val) || 0
  return num.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 4 })
}

const loadSupportingData = async () => {
  try {
    const [supRes, prRes] = await Promise.all([
      apiFetch('/suppliers', { query: { is_active: 1, per_page: 100 } }),
      apiFetch('/requisitions', { query: { type: 'PR', status: 'GM Approved', per_page: 100 } })
    ])
    suppliers.value = supRes.data || []
    eligiblePrs.value = prRes.data || []
  } catch (_) {
    // Handled on main load
  }
}

const loadPurchaseOrders = async (page = 1) => {
  loading.value = true
  loadError.value = ''

  try {
    const query = { page, per_page: 10 }
    if (filters.search) query.search = filters.search
    if (filters.status) query.status = filters.status
    if (filters.supplier_id) query.supplier_id = filters.supplier_id

    const res = await apiFetch('/purchase-orders', { query })
    purchaseOrders.value = res.data || []
    paginationMeta.value = {
      current_page: res.current_page || 1,
      last_page: res.last_page || 1,
      total: res.total || 0,
      from: res.from || 0,
      to: res.to || 0
    }
  } catch (err) {
    const parsed = extractApiError(err, 'Failed to load purchase orders.')
    loadError.value = parsed.message
  } finally {
    loading.value = false
  }
}

const onSelectPr = () => {
  const pr = eligiblePrs.value.find((p) => Number(p.id) === Number(form.requisition_id))
  if (!pr) return
  form.items = (pr.items || []).map((li) => ({
    item_id: li.item_id,
    item_label: `${li.item?.sku || ''} — ${li.item?.name || ''}`,
    uom: li.uom,
    ordered_quantity: Number(li.requested_quantity),
    unit_price: 1000,
    notes: li.notes || ''
  }))
}

const openCreateModal = async () => {
  await loadSupportingData()
  editingId.value = null
  form.requisition_id = eligiblePrs.value[0]?.id ?? ''
  form.supplier_id = suppliers.value[0]?.id ?? ''
  form.notes = ''
  form.items = []
  if (form.requisition_id) {
    onSelectPr()
  }
  formError.value = ''
  fieldErrors.value = {}
  showModal.value = true
}

const openEditModal = (po) => {
  editingId.value = po.id
  form.requisition_id = po.requisition_id
  form.supplier_id = po.supplier_id
  form.notes = po.notes || ''
  if (po.requisition && !eligiblePrs.value.some((p) => Number(p.id) === Number(po.requisition.id))) {
    eligiblePrs.value.push(po.requisition)
  }
  form.items = (po.items || []).map((li) => ({
    item_id: li.item_id,
    item_label: `${li.item?.sku || ''} — ${li.item?.name || ''}`,
    uom: li.uom,
    ordered_quantity: Number(li.ordered_quantity),
    unit_price: Number(li.unit_price),
    notes: li.notes || ''
  }))
  formError.value = ''
  fieldErrors.value = {}
  showModal.value = true
}

const closeModal = () => {
  showModal.value = false
}

const savePurchaseOrder = async () => {
  if (submitting.value) return
  submitting.value = true
  formError.value = ''
  fieldErrors.value = {}

  const payload = {
    requisition_id: Number(form.requisition_id),
    supplier_id: Number(form.supplier_id),
    notes: form.notes,
    items: form.items.map((li) => ({
      item_id: Number(li.item_id),
      ordered_quantity: Number(li.ordered_quantity),
      unit_price: Number(li.unit_price),
      notes: li.notes || null
    }))
  }

  try {
    if (editingId.value) {
      await apiFetch(`/purchase-orders/${editingId.value}`, {
        method: 'PUT',
        body: payload
      })
      showToast('Draft Purchase Order updated.')
    } else {
      await apiFetch('/purchase-orders', {
        method: 'POST',
        body: payload
      })
      showToast('Purchase Order created from PR.')
    }
    closeModal()
    await Promise.all([loadSupportingData(), loadPurchaseOrders(paginationMeta.value.current_page)])
  } catch (err) {
    const parsed = extractApiError(err, 'Unable to save Purchase Order.')
    formError.value = parsed.message
    fieldErrors.value = parsed.fieldErrors
  } finally {
    submitting.value = false
  }
}

const handleSendPo = async (po) => {
  if (busyId.value) return
  busyId.value = po.id
  try {
    await apiFetch(`/purchase-orders/${po.id}/send`, { method: 'POST' })
    showToast(`Purchase Order ${po.po_number} marked as Sent.`)
    await loadPurchaseOrders(paginationMeta.value.current_page)
  } catch (err) {
    const parsed = extractApiError(err, 'Unable to send Purchase Order.')
    showToast(parsed.message, 'error')
  } finally {
    busyId.value = null
  }
}

onMounted(async () => {
  await Promise.all([loadSupportingData(), loadPurchaseOrders(1)])
})
</script>
