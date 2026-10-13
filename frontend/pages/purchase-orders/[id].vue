<template>
  <div class="space-y-6">
    <div class="flex items-center justify-between">
      <NuxtLink
        to="/purchase-orders"
        class="text-xs font-semibold text-slate-600 hover:text-slate-900"
      >
        ← Back to Purchase Orders List
      </NuxtLink>
    </div>

    <div v-if="loading" class="bg-white rounded-xl border border-slate-200 p-8 text-center text-sm text-slate-500">
      Loading Purchase Order details...
    </div>

    <div
      v-else-if="loadError"
      class="rounded-xl bg-red-50 border border-red-200 p-5 text-sm text-red-700"
    >
      {{ loadError }}
    </div>

    <template v-else-if="po">
      <!-- PO Header Summary Card -->
      <div class="bg-white rounded-xl border border-slate-200 p-6 shadow-sm space-y-4">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 border-b border-slate-200 pb-4">
          <div>
            <div class="flex items-center gap-2.5">
              <span class="inline-flex rounded px-2.5 py-0.5 text-xs font-bold bg-amber-100 text-amber-900">
                Purchase Order
              </span>
              <StatusBadge :status="po.status" />
            </div>
            <h2 class="text-xl font-bold text-slate-900 mt-1.5 font-mono">
              {{ po.po_number }}
            </h2>
          </div>

          <div class="flex items-center gap-2">
            <button
              v-if="canManagePo && po.status === 'Draft'"
              type="button"
              :disabled="actionLoading"
              class="rounded-lg bg-emerald-600 hover:bg-emerald-700 disabled:opacity-50 text-white font-semibold px-4 py-2 text-xs shadow-sm"
              @click="handleSend"
            >
              {{ actionLoading ? 'Sending...' : 'Send PO to Supplier' }}
            </button>
          </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 text-xs">
          <div class="p-3 rounded-lg bg-slate-50 border border-slate-100">
            <div class="text-slate-500 font-medium">Supplier</div>
            <div class="text-slate-900 font-semibold mt-0.5">
              {{ po.supplier?.code }} — {{ po.supplier?.name }}
            </div>
            <div class="text-slate-400">{{ po.supplier?.contact_person || '' }}</div>
          </div>

          <div class="p-3 rounded-lg bg-slate-50 border border-slate-100">
            <div class="text-slate-500 font-medium">Originating PR & Department</div>
            <div class="mt-0.5">
              <NuxtLink
                v-if="po.requisition"
                :to="`/requisitions/${po.requisition.id}`"
                class="font-mono font-bold text-indigo-700 hover:underline"
              >
                {{ po.requisition.requisition_number }}
              </NuxtLink>
              <span v-else class="text-slate-600">—</span>
            </div>
            <div class="text-slate-500">{{ po.requisition?.department?.name || '' }}</div>
          </div>

          <div class="p-3 rounded-lg bg-slate-50 border border-slate-100">
            <div class="text-slate-500 font-medium">Created / Sent Audit</div>
            <div class="text-slate-900 font-semibold mt-0.5">
              Created by: {{ po.creator?.name || '—' }}
            </div>
            <div v-if="po.sender" class="text-emerald-700 font-medium">
              Sent by: {{ po.sender.name }} ({{ formatDate(po.sent_at) }})
            </div>
            <div v-else class="text-slate-400">Not sent yet</div>
          </div>

          <div class="p-3 rounded-lg bg-amber-50/70 border border-amber-200">
            <div class="text-amber-800 font-medium">Server-Calculated Grand Total</div>
            <div class="text-lg font-bold font-mono text-slate-900 mt-0.5">
              {{ formatCurrency(po.total_amount) }}
            </div>
          </div>
        </div>

        <div v-if="po.notes" class="text-xs text-slate-700 bg-slate-50 p-3 rounded-lg border border-slate-100">
          <span class="font-semibold text-slate-900">Notes:</span> {{ po.notes }}
        </div>
      </div>

      <!-- PO Line Items Table (Visually distinct Ordered vs Received Quantities) -->
      <div class="bg-white rounded-xl border border-slate-200 overflow-hidden shadow-sm">
        <div class="px-6 py-4 border-b border-slate-200 flex items-center justify-between">
          <span class="font-bold text-sm text-slate-900">Purchase Order Line Items</span>
          <span class="text-xs text-slate-500">Ordered vs Received Quantities</span>
        </div>

        <div class="overflow-x-auto">
          <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-xs font-semibold uppercase text-slate-600">
              <tr>
                <th class="px-4 py-3 text-left">SKU</th>
                <th class="px-4 py-3 text-left">Item Name</th>
                <th class="px-4 py-3 text-left">UoM</th>
                <th class="px-4 py-3 text-right bg-indigo-50/60 text-indigo-900">Ordered Qty</th>
                <th class="px-4 py-3 text-right bg-emerald-50/60 text-emerald-900">Received Qty</th>
                <th class="px-4 py-3 text-right bg-amber-50/60 text-amber-900">Outstanding Qty</th>
                <th class="px-4 py-3 text-right">Unit Price</th>
                <th class="px-4 py-3 text-right">Line Total</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-200">
              <tr v-for="li in po.items" :key="li.id">
                <td class="px-4 py-3 font-mono text-xs font-semibold text-slate-900">
                  {{ li.item?.sku || '—' }}
                </td>
                <td class="px-4 py-3 font-medium text-slate-900">
                  {{ li.item?.name || '—' }}
                </td>
                <td class="px-4 py-3 font-mono text-xs text-slate-700">
                  {{ li.uom }}
                </td>
                <td class="px-4 py-3 text-right font-mono text-xs font-bold bg-indigo-50/30 text-indigo-900">
                  {{ li.ordered_quantity }}
                </td>
                <td class="px-4 py-3 text-right font-mono text-xs font-bold bg-emerald-50/30 text-emerald-800">
                  {{ li.received_quantity }}
                </td>
                <td class="px-4 py-3 text-right font-mono text-xs font-semibold bg-amber-50/30 text-amber-900">
                  {{ Math.max(0, Number(li.ordered_quantity) - Number(li.received_quantity)).toFixed(3) }}
                </td>
                <td class="px-4 py-3 text-right font-mono text-xs text-slate-800">
                  {{ formatCurrency(li.unit_price) }}
                </td>
                <td class="px-4 py-3 text-right font-mono text-xs font-bold text-slate-900">
                  {{ formatCurrency(li.line_total) }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </template>
  </div>
</template>

<script setup>
const route = useRoute()
const { apiFetch, extractApiError } = useApi()
const { hasRole } = useAuth()
const { showToast } = useToast()

const canManagePo = computed(() => hasRole(['procurement_officer', 'admin']))

const po = ref(null)
const loading = ref(false)
const loadError = ref('')
const actionLoading = ref(false)

const formatDate = (iso) => {
  if (!iso) return '—'
  return new Date(iso).toLocaleString()
}

const formatCurrency = (val) => {
  const num = Number(val) || 0
  return num.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 4 })
}

const loadDetail = async () => {
  loading.value = true
  loadError.value = ''

  try {
    const res = await apiFetch(`/purchase-orders/${route.params.id}`)
    po.value = res.data || null
  } catch (err) {
    const parsed = extractApiError(err, 'Unable to load Purchase Order details.')
    loadError.value = parsed.message
  } finally {
    loading.value = false
  }
}

const handleSend = async () => {
  if (actionLoading.value || !po.value) return
  actionLoading.value = true
  try {
    const res = await apiFetch(`/purchase-orders/${po.value.id}/send`, { method: 'POST' })
    po.value = res.data
    showToast(`Purchase Order ${po.value.po_number} sent to supplier.`)
  } catch (err) {
    const parsed = extractApiError(err, 'Failed to send Purchase Order.')
    showToast(parsed.message, 'error')
  } finally {
    actionLoading.value = false
  }
}

onMounted(() => {
  loadDetail()
})
</script>
