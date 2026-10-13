<template>
  <div class="space-y-5">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
      <div>
        <h2 class="text-xl font-bold text-slate-900">Goods Receiving Notes (GRN)</h2>
        <p class="text-xs text-slate-500 mt-0.5">
          Record supplier deliveries against Sent / Partial Received POs. Stock IN is posted atomically only upon Store Manager approval.
        </p>
      </div>
      <button
        v-if="canCreateGrn"
        type="button"
        class="rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white font-semibold px-4 py-2 text-sm shadow-sm"
        @click="openCreateModal"
      >
        + Record New GRN
      </button>
    </div>

    <!-- Filters Bar -->
    <div class="bg-white rounded-xl border border-slate-200 p-4 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3">
      <div class="lg:col-span-2">
        <label class="block text-xs font-medium text-slate-600 mb-1">Search</label>
        <input
          v-model="filters.search"
          type="text"
          placeholder="Search GRN number, delivery note, or notes..."
          class="w-full rounded-lg border border-slate-300 px-3 py-1.5 text-sm"
          @keyup.enter="loadGrns(1)"
        />
      </div>

      <div>
        <label class="block text-xs font-medium text-slate-600 mb-1">Status</label>
        <select
          v-model="filters.status"
          class="w-full rounded-lg border border-slate-300 px-3 py-1.5 text-sm bg-white"
          @change="loadGrns(1)"
        >
          <option value="">All Statuses</option>
          <option value="Pending">Pending (Awaiting Approval)</option>
          <option value="Approved">Approved (Stock IN Posted)</option>
          <option value="Rejected">Rejected</option>
        </select>
      </div>

      <div>
        <label class="block text-xs font-medium text-slate-600 mb-1">Receiving Store</label>
        <select
          v-model="filters.store_id"
          class="w-full rounded-lg border border-slate-300 px-3 py-1.5 text-sm bg-white"
          @change="loadGrns(1)"
        >
          <option value="">All Stores</option>
          <option v-for="st in stores" :key="st.id" :value="st.id">
            {{ st.code }} — {{ st.name }}
          </option>
        </select>
      </div>

      <div class="flex items-end">
        <button
          type="button"
          class="w-full rounded-lg bg-slate-900 hover:bg-slate-800 text-white px-4 py-1.5 text-sm font-medium"
          @click="loadGrns(1)"
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

    <!-- GRN Table -->
    <div class="bg-white rounded-xl border border-slate-200 overflow-hidden shadow-sm">
      <div v-if="loading" class="p-8 text-center text-sm text-slate-500">
        Loading Goods Receiving Notes...
      </div>

      <div v-else-if="grns.length === 0" class="p-8 text-center text-sm text-slate-500">
        No Goods Receiving Notes found matching the current filters.
      </div>

      <div v-else class="overflow-x-auto">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
          <thead class="bg-slate-50 text-xs font-semibold uppercase text-slate-600">
            <tr>
              <th class="px-4 py-3 text-left">GRN Number</th>
              <th class="px-4 py-3 text-left">PO Reference & Supplier</th>
              <th class="px-4 py-3 text-left">Receiving Store</th>
              <th class="px-4 py-3 text-left">Received By</th>
              <th class="px-4 py-3 text-left">Approved By</th>
              <th class="px-4 py-3 text-left">Status</th>
              <th class="px-4 py-3 text-right">Actions</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-200">
            <tr v-for="grn in grns" :key="grn.id" class="hover:bg-slate-50">
              <td class="px-4 py-3 font-mono text-xs font-bold text-slate-900">
                <NuxtLink :to="`/grns/${grn.id}`" class="hover:text-emerald-700 underline">
                  {{ grn.grn_number }}
                </NuxtLink>
                <div v-if="grn.delivery_note_number" class="text-[11px] text-slate-400">
                  DN: {{ grn.delivery_note_number }}
                </div>
              </td>
              <td class="px-4 py-3 text-xs text-slate-800">
                <NuxtLink
                  v-if="grn.purchase_order"
                  :to="`/purchase-orders/${grn.purchase_order.id}`"
                  class="font-mono font-bold text-indigo-700 hover:underline"
                >
                  {{ grn.purchase_order.po_number }}
                </NuxtLink>
                <div class="text-slate-500">{{ grn.purchase_order?.supplier?.name || '' }}</div>
              </td>
              <td class="px-4 py-3 text-xs text-slate-700">
                <div class="font-semibold">{{ grn.store?.name || '—' }}</div>
                <div class="text-slate-400">{{ grn.store?.code || '' }}</div>
              </td>
              <td class="px-4 py-3 text-xs text-slate-700">
                <div>{{ grn.receiver?.name || '—' }}</div>
                <div class="text-slate-400">{{ formatDate(grn.received_at) }}</div>
              </td>
              <td class="px-4 py-3 text-xs text-slate-700">
                <div v-if="grn.approver" class="font-medium text-emerald-700">
                  {{ grn.approver.name }}
                </div>
                <div v-else class="text-slate-400">—</div>
              </td>
              <td class="px-4 py-3">
                <StatusBadge :status="grn.status" />
              </td>
              <td class="px-4 py-3 text-right space-x-2">
                <NuxtLink
                  :to="`/grns/${grn.id}`"
                  class="text-xs font-semibold text-slate-700 hover:text-slate-950 underline"
                >
                  View / Review
                </NuxtLink>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <PaginationBar :meta="paginationMeta" @change="loadGrns" />
    </div>

    <!-- Create GRN Modal -->
    <div
      v-if="showModal"
      class="fixed inset-0 z-40 flex items-center justify-center bg-slate-950/60 p-4 overflow-y-auto"
    >
      <div class="w-full max-w-4xl rounded-xl bg-white shadow-xl border border-slate-200 overflow-hidden my-8">
        <div class="px-6 py-4 bg-slate-900 text-white flex items-center justify-between">
          <h3 class="text-base font-bold">Record Goods Receiving Note (GRN)</h3>
          <button type="button" class="text-slate-400 hover:text-white" @click="closeModal">
            ✕
          </button>
        </div>

        <form class="p-6 space-y-4 max-h-[82vh] overflow-y-auto" @submit.prevent="saveGrn">
          <div v-if="formError" class="rounded-lg bg-red-50 border border-red-200 p-3 text-xs text-red-700">
            {{ formError }}
          </div>

          <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div>
              <label class="block text-xs font-semibold text-slate-700 mb-1">
                Purchase Order (Sent / Partial) *
              </label>
              <select
                v-model="form.purchase_order_id"
                required
                class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm bg-white"
                @change="onSelectPo"
              >
                <option value="" disabled>Select Eligible PO</option>
                <option v-for="po in eligiblePos" :key="po.id" :value="po.id">
                  {{ po.po_number }} — {{ po.supplier?.name }} ({{ po.status }})
                </option>
              </select>
              <p v-if="fieldErrors.purchase_order_id" class="mt-1 text-xs text-red-600">
                {{ fieldErrors.purchase_order_id[0] }}
              </p>
            </div>

            <div>
              <label class="block text-xs font-semibold text-slate-700 mb-1">Receiving Store *</label>
              <select
                v-model="form.store_id"
                :disabled="Boolean(user?.store_id)"
                required
                class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm bg-white disabled:bg-slate-100"
              >
                <option value="" disabled>Select Store</option>
                <option v-for="st in stores" :key="st.id" :value="st.id">
                  {{ st.code }} — {{ st.name }}
                </option>
              </select>
              <p v-if="fieldErrors.store_id" class="mt-1 text-xs text-red-600">
                {{ fieldErrors.store_id[0] }}
              </p>
            </div>

            <div>
              <label class="block text-xs font-semibold text-slate-700 mb-1">Supplier Delivery Note #</label>
              <input
                v-model="form.delivery_note_number"
                type="text"
                class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                placeholder="DN-2026-001"
              />
            </div>
          </div>

          <div>
            <label class="block text-xs font-semibold text-slate-700 mb-1">Receiving Notes</label>
            <textarea
              v-model="form.notes"
              rows="2"
              class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
              placeholder="Condition of goods, vehicle number, or inspection remarks..."
            ></textarea>
          </div>

          <!-- PO Lines to Receive -->
          <div class="space-y-2 pt-2 border-t border-slate-200">
            <div class="flex items-center justify-between">
              <label class="text-xs font-bold uppercase tracking-wider text-slate-700">
                Delivery Quantities (Over-receipt strictly prohibited) *
              </label>
            </div>

            <div
              v-if="form.items.length === 0"
              class="rounded-lg bg-slate-50 border border-slate-200 p-4 text-center text-xs text-slate-500"
            >
              Select a Sent or Partial Received Purchase Order above to load remaining receivable items.
            </div>

            <div
              v-for="(line, idx) in form.items"
              :key="line.po_item_id"
              class="grid grid-cols-1 sm:grid-cols-12 gap-2 items-center bg-slate-50 p-3 rounded-lg border border-slate-200 text-xs"
            >
              <div class="sm:col-span-4 flex items-center gap-2">
                <input
                  v-model="line.include"
                  type="checkbox"
                  class="rounded border-slate-300 text-emerald-600"
                />
                <div>
                  <div class="font-semibold text-slate-900">{{ line.item_label }}</div>
                  <div class="text-slate-500 font-mono">UoM: {{ line.uom }}</div>
                </div>
              </div>

              <div class="sm:col-span-2 text-right">
                <div class="text-[11px] text-slate-500">Ordered</div>
                <div class="font-mono font-semibold text-slate-800">{{ line.ordered_quantity }}</div>
              </div>

              <div class="sm:col-span-2 text-right">
                <div class="text-[11px] text-slate-500">Prev. Received</div>
                <div class="font-mono font-semibold text-emerald-700">{{ line.previously_received }}</div>
              </div>

              <div class="sm:col-span-2 text-right">
                <div class="text-[11px] text-slate-500">Remaining</div>
                <div class="font-mono font-bold text-amber-800">{{ line.remaining_quantity }}</div>
              </div>

              <div class="sm:col-span-2">
                <label class="block text-[11px] font-semibold text-slate-700 mb-0.5">This Delivery *</label>
                <input
                  v-model="line.received_quantity"
                  type="number"
                  step="0.001"
                  min="0.001"
                  :max="line.remaining_quantity"
                  :disabled="!line.include"
                  class="w-full rounded border border-slate-300 px-2.5 py-1.5 text-xs bg-white disabled:bg-slate-100"
                />
                <p v-if="fieldErrors[`items.${idx}.received_quantity`]" class="mt-0.5 text-[11px] text-red-600">
                  {{ fieldErrors[`items.${idx}.received_quantity`][0] }}
                </p>
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
              :disabled="submitting || selectedLineItems.length === 0"
              class="rounded-lg bg-emerald-600 hover:bg-emerald-700 disabled:opacity-50 px-4 py-2 text-xs font-semibold text-white"
            >
              {{ submitting ? 'Submitting...' : 'Submit Pending GRN' }}
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

const canCreateGrn = computed(() => hasRole(['store_keeper', 'store_manager', 'admin']))

const grns = ref([])
const stores = ref([])
const eligiblePos = ref([])

const loading = ref(false)
const loadError = ref('')

const filters = reactive({
  search: '',
  status: '',
  store_id: ''
})

const paginationMeta = ref({
  current_page: 1,
  last_page: 1,
  total: 0,
  from: 0,
  to: 0
})

const showModal = ref(false)
const submitting = ref(false)
const formError = ref('')
const fieldErrors = ref({})

const form = reactive({
  purchase_order_id: '',
  store_id: '',
  delivery_note_number: '',
  notes: '',
  items: []
})

const selectedLineItems = computed(() =>
  form.items.filter((li) => li.include && Number(li.received_quantity) > 0)
)

const formatDate = (iso) => {
  if (!iso) return '—'
  return new Date(iso).toLocaleString()
}

const loadSupportingData = async () => {
  try {
    const [storeRes, poRes] = await Promise.all([
      apiFetch('/stores', { query: { is_active: 1, per_page: 100 } }),
      apiFetch('/purchase-orders', { query: { per_page: 100 } })
    ])
    stores.value = storeRes.data || []
    eligiblePos.value = (poRes.data || []).filter((p) =>
      ['Sent', 'Partial Received'].includes(p.status)
    )
  } catch (_) {
    // Handled on main load
  }
}

const loadGrns = async (page = 1) => {
  loading.value = true
  loadError.value = ''

  try {
    const query = { page, per_page: 10 }
    if (filters.search) query.search = filters.search
    if (filters.status) query.status = filters.status
    if (filters.store_id) query.store_id = filters.store_id

    const res = await apiFetch('/grns', { query })
    grns.value = res.data || []
    paginationMeta.value = {
      current_page: res.current_page || 1,
      last_page: res.last_page || 1,
      total: res.total || 0,
      from: res.from || 0,
      to: res.to || 0
    }
  } catch (err) {
    const parsed = extractApiError(err, 'Failed to load Goods Receiving Notes.')
    loadError.value = parsed.message
  } finally {
    loading.value = false
  }
}

const onSelectPo = () => {
  const po = eligiblePos.value.find((p) => Number(p.id) === Number(form.purchase_order_id))
  if (!po) return
  form.items = (po.items || [])
    .map((pi) => {
      const rem = Math.max(0, Number(pi.ordered_quantity) - Number(pi.received_quantity))
      return {
        po_item_id: pi.id,
        item_id: pi.item_id,
        item_label: `${pi.item?.sku || ''} — ${pi.item?.name || ''}`,
        uom: pi.uom,
        ordered_quantity: Number(pi.ordered_quantity).toFixed(3),
        previously_received: Number(pi.received_quantity).toFixed(3),
        remaining_quantity: rem.toFixed(3),
        received_quantity: rem > 0 ? Number(rem.toFixed(3)) : 0,
        include: rem > 0
      }
    })
    .filter((line) => Number(line.remaining_quantity) > 0)
}

const openCreateModal = async () => {
  await loadSupportingData()
  form.purchase_order_id = eligiblePos.value[0]?.id ?? ''
  form.store_id = user.value?.store_id || (stores.value[0]?.id ?? '')
  form.delivery_note_number = ''
  form.notes = ''
  form.items = []
  if (form.purchase_order_id) {
    onSelectPo()
  }
  formError.value = ''
  fieldErrors.value = {}
  showModal.value = true
}

const closeModal = () => {
  showModal.value = false
}

const saveGrn = async () => {
  if (submitting.value || selectedLineItems.value.length === 0) return
  submitting.value = true
  formError.value = ''
  fieldErrors.value = {}

  const payload = {
    purchase_order_id: Number(form.purchase_order_id),
    store_id: Number(form.store_id),
    delivery_note_number: form.delivery_note_number || null,
    notes: form.notes || null,
    items: selectedLineItems.value.map((li) => ({
      po_item_id: Number(li.po_item_id),
      item_id: Number(li.item_id),
      received_quantity: Number(li.received_quantity)
    }))
  }

  try {
    await apiFetch('/grns', {
      method: 'POST',
      body: payload
    })
    showToast('Pending GRN recorded successfully.')
    closeModal()
    await loadGrns(1)
  } catch (err) {
    const parsed = extractApiError(err, 'Unable to record GRN.')
    formError.value = parsed.message
    fieldErrors.value = parsed.fieldErrors
  } finally {
    submitting.value = false
  }
}

onMounted(async () => {
  await Promise.all([loadSupportingData(), loadGrns(1)])
})
</script>
