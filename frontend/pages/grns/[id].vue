<template>
  <div class="space-y-6">
    <div class="flex items-center justify-between">
      <NuxtLink
        to="/grns"
        class="text-xs font-semibold text-slate-600 hover:text-slate-900"
      >
        ← Back to Goods Receiving Notes List
      </NuxtLink>
    </div>

    <div v-if="loading" class="bg-white rounded-xl border border-slate-200 p-8 text-center text-sm text-slate-500">
      Loading Goods Receiving Note details...
    </div>

    <div
      v-else-if="loadError"
      class="rounded-xl bg-red-50 border border-red-200 p-5 text-sm text-red-700"
    >
      {{ loadError }}
    </div>

    <template v-else-if="grn">
      <!-- GRN Header Summary -->
      <div class="bg-white rounded-xl border border-slate-200 p-6 shadow-sm space-y-4">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 border-b border-slate-200 pb-4">
          <div>
            <div class="flex items-center gap-2.5">
              <span class="inline-flex rounded px-2.5 py-0.5 text-xs font-bold bg-emerald-100 text-emerald-900">
                Goods Receiving Note (GRN)
              </span>
              <StatusBadge :status="grn.status" />
            </div>
            <h2 class="text-xl font-bold text-slate-900 mt-1.5 font-mono">
              {{ grn.grn_number }}
            </h2>
          </div>

          <!-- Store Manager Approval / Rejection Controls -->
          <div class="flex flex-wrap items-center gap-2">
            <button
              v-if="canApproveGrn"
              type="button"
              :disabled="actionLoading"
              class="rounded-lg bg-emerald-600 hover:bg-emerald-700 disabled:opacity-50 text-white font-semibold px-4 py-2 text-xs shadow-sm"
              @click="handleApprove"
            >
              {{ actionLoading ? 'Posting Stock IN...' : 'Approve GRN & Post Stock IN' }}
            </button>

            <button
              v-if="canApproveGrn"
              type="button"
              :disabled="actionLoading"
              class="rounded-lg bg-red-600 hover:bg-red-700 disabled:opacity-50 text-white font-semibold px-4 py-2 text-xs shadow-sm"
              @click="showRejectBox = !showRejectBox"
            >
              Reject GRN
            </button>
          </div>
        </div>

        <!-- Rejection Box -->
        <div
          v-if="showRejectBox && canApproveGrn"
          class="rounded-lg bg-red-50 border border-red-200 p-4 space-y-3"
        >
          <label class="block text-xs font-bold text-red-800">
            Rejection Reason (Required) *
          </label>
          <textarea
            v-model="rejectionReason"
            rows="2"
            class="w-full rounded-lg border border-red-300 px-3 py-2 text-sm bg-white"
            placeholder="Reason for rejecting this delivery..."
          ></textarea>
          <div class="flex justify-end gap-2">
            <button
              type="button"
              class="rounded border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700"
              @click="showRejectBox = false"
            >
              Cancel
            </button>
            <button
              type="button"
              :disabled="actionLoading || !rejectionReason.trim()"
              class="rounded bg-red-600 hover:bg-red-700 disabled:opacity-50 px-3.5 py-1.5 text-xs font-semibold text-white"
              @click="handleReject"
            >
              Confirm Rejection
            </button>
          </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 text-xs">
          <div class="p-3 rounded-lg bg-slate-50 border border-slate-100">
            <div class="text-slate-500 font-medium">Purchase Order & Supplier</div>
            <div class="mt-0.5">
              <NuxtLink
                v-if="grn.purchase_order"
                :to="`/purchase-orders/${grn.purchase_order.id}`"
                class="font-mono font-bold text-indigo-700 hover:underline"
              >
                {{ grn.purchase_order.po_number }}
              </NuxtLink>
              <span v-if="grn.purchase_order" class="ml-1.5 text-[11px] text-slate-500">
                ({{ grn.purchase_order.status }})
              </span>
            </div>
            <div class="text-slate-700 font-medium mt-0.5">
              {{ grn.purchase_order?.supplier?.name || '—' }}
            </div>
          </div>

          <div class="p-3 rounded-lg bg-slate-50 border border-slate-100">
            <div class="text-slate-500 font-medium">Receiving Store</div>
            <div class="text-slate-900 font-semibold mt-0.5">
              {{ grn.store?.code }} — {{ grn.store?.name }}
            </div>
            <div v-if="grn.delivery_note_number" class="text-slate-500 mt-0.5">
              Delivery Note: {{ grn.delivery_note_number }}
            </div>
          </div>

          <div class="p-3 rounded-lg bg-slate-50 border border-slate-100">
            <div class="text-slate-500 font-medium">Recorded By</div>
            <div class="text-slate-900 font-semibold mt-0.5">
              {{ grn.receiver?.name || '—' }}
            </div>
            <div class="text-slate-400">{{ formatDate(grn.received_at) }}</div>
          </div>

          <div class="p-3 rounded-lg bg-slate-50 border border-slate-100">
            <div class="text-slate-500 font-medium">Store Manager Approval</div>
            <div v-if="grn.approver" class="text-emerald-700 font-semibold mt-0.5">
              Approved by {{ grn.approver.name }}
              <div class="text-slate-400 font-normal">{{ formatDate(grn.approved_at) }}</div>
            </div>
            <div v-else-if="grn.status === 'Rejected'" class="text-red-700 font-semibold mt-0.5">
              Rejected by {{ grn.rejector?.name || 'Store Manager' }}
              <div class="text-red-600 font-normal">{{ grn.rejection_reason }}</div>
            </div>
            <div v-else class="text-amber-700 font-medium mt-0.5">
              Awaiting Store Manager Approval (No stock posted yet)
            </div>
          </div>
        </div>
      </div>

      <!-- GRN Line Items -->
      <div class="bg-white rounded-xl border border-slate-200 overflow-hidden shadow-sm">
        <div class="px-6 py-4 border-b border-slate-200 flex items-center justify-between">
          <span class="font-bold text-sm text-slate-900">GRN Line Items</span>
          <span class="text-xs text-slate-500">
            Ordered vs Cumulative PO Received vs This GRN Delivery
          </span>
        </div>

        <div class="overflow-x-auto">
          <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-xs font-semibold uppercase text-slate-600">
              <tr>
                <th class="px-4 py-3 text-left">SKU</th>
                <th class="px-4 py-3 text-left">Item Name</th>
                <th class="px-4 py-3 text-left">UoM</th>
                <th class="px-4 py-3 text-right">PO Ordered Qty</th>
                <th class="px-4 py-3 text-right">PO Total Received</th>
                <th class="px-4 py-3 text-right bg-emerald-50 text-emerald-900">This GRN Qty</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-200">
              <tr v-for="gi in grn.items" :key="gi.id">
                <td class="px-4 py-3 font-mono text-xs font-semibold text-slate-900">
                  {{ gi.item?.sku || '—' }}
                </td>
                <td class="px-4 py-3 font-medium text-slate-900">
                  {{ gi.item?.name || '—' }}
                </td>
                <td class="px-4 py-3 font-mono text-xs text-slate-700">
                  {{ gi.uom }}
                </td>
                <td class="px-4 py-3 text-right font-mono text-xs text-slate-700">
                  {{ gi.po_item?.ordered_quantity || '—' }}
                </td>
                <td class="px-4 py-3 text-right font-mono text-xs text-slate-700">
                  {{ gi.po_item?.received_quantity || '—' }}
                </td>
                <td class="px-4 py-3 text-right font-mono text-xs font-bold bg-emerald-50/40 text-emerald-900">
                  +{{ gi.received_quantity }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Posted Stock Ledger Entries (Visible once Approved) -->
      <div
        v-if="grn.stock_ledger_entries && grn.stock_ledger_entries.length > 0"
        class="bg-white rounded-xl border border-emerald-200 overflow-hidden shadow-sm"
      >
        <div class="px-6 py-4 bg-emerald-50/60 border-b border-emerald-200 font-bold text-sm text-emerald-950">
          Posted Stock Ledger Movements (Atomic Stock IN)
        </div>
        <div class="overflow-x-auto">
          <table class="min-w-full divide-y divide-slate-200 text-xs">
            <thead class="bg-slate-50 font-semibold uppercase text-slate-600">
              <tr>
                <th class="px-4 py-2.5 text-left">Ledger ID</th>
                <th class="px-4 py-2.5 text-left">Type</th>
                <th class="px-4 py-2.5 text-right">Qty Delta</th>
                <th class="px-4 py-2.5 text-right">Store Balance After</th>
                <th class="px-4 py-2.5 text-left">Posted By</th>
                <th class="px-4 py-2.5 text-left">Timestamp</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-200">
              <tr v-for="entry in grn.stock_ledger_entries" :key="entry.id">
                <td class="px-4 py-2.5 font-mono">#{{ entry.id }}</td>
                <td class="px-4 py-2.5">
                  <span class="rounded bg-emerald-100 text-emerald-800 px-2 py-0.5 font-bold">
                    {{ entry.transaction_type }}
                  </span>
                </td>
                <td class="px-4 py-2.5 text-right font-mono font-bold text-emerald-700">
                  +{{ entry.quantity }}
                </td>
                <td class="px-4 py-2.5 text-right font-mono font-bold text-slate-900">
                  {{ entry.balance_after }}
                </td>
                <td class="px-4 py-2.5">{{ entry.actor?.name || 'Store Manager' }}</td>
                <td class="px-4 py-2.5 text-slate-500">{{ formatDate(entry.transaction_at) }}</td>
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
const { user, hasRole } = useAuth()
const { showToast } = useToast()

const grn = ref(null)
const loading = ref(false)
const loadError = ref('')
const actionLoading = ref(false)
const showRejectBox = ref(false)
const rejectionReason = ref('')

const canApproveGrn = computed(() => {
  if (!grn.value || !user.value) return false
  if (grn.value.status !== 'Pending') return false
  if (!hasRole('store_manager')) return false
  if (user.value.store_id && Number(user.value.store_id) !== Number(grn.value.store_id)) {
    return false
  }
  return true
})

const formatDate = (iso) => {
  if (!iso) return '—'
  return new Date(iso).toLocaleString()
}

const loadDetail = async () => {
  loading.value = true
  loadError.value = ''

  try {
    const res = await apiFetch(`/grns/${route.params.id}`)
    grn.value = res.data || null
  } catch (err) {
    const parsed = extractApiError(err, 'Unable to load GRN details.')
    loadError.value = parsed.message
  } finally {
    loading.value = false
  }
}

const handleApprove = async () => {
  if (actionLoading.value || !grn.value) return
  actionLoading.value = true
  try {
    const res = await apiFetch(`/grns/${grn.value.id}/approve`, { method: 'POST' })
    grn.value = res.data
    showToast(`GRN ${grn.value.grn_number} approved and Stock IN posted.`)
  } catch (err) {
    const parsed = extractApiError(err, 'Failed to approve GRN.')
    showToast(parsed.message, 'error')
  } finally {
    actionLoading.value = false
  }
}

const handleReject = async () => {
  if (actionLoading.value || !grn.value || !rejectionReason.value.trim()) return
  actionLoading.value = true
  try {
    const res = await apiFetch(`/grns/${grn.value.id}/reject`, {
      method: 'POST',
      body: { rejection_reason: rejectionReason.value }
    })
    grn.value = res.data
    showRejectBox.value = false
    rejectionReason.value = ''
    showToast(`GRN ${grn.value.grn_number} rejected.`)
  } catch (err) {
    const parsed = extractApiError(err, 'Failed to reject GRN.')
    showToast(parsed.message, 'error')
  } finally {
    actionLoading.value = false
  }
}

onMounted(() => {
  loadDetail()
})
</script>
