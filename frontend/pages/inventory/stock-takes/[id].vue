<template>
  <div class="space-y-6 max-w-6xl mx-auto">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
      <div>
        <NuxtLink
          to="/inventory/stock-takes"
          class="text-xs font-semibold text-purple-600 hover:text-purple-800 flex items-center gap-1 mb-1"
        >
          &larr; Back to Stock Takes
        </NuxtLink>
        <div class="flex items-center gap-3">
          <h2 class="text-xl font-bold text-slate-900 font-mono">
            {{ stockTake?.stock_take_number || 'Loading...' }}
          </h2>
          <StatusBadge v-if="stockTake" :status="stockTake.status" />
        </div>
      </div>

      <!-- Action Buttons -->
      <div v-if="stockTake && stockTake.status === 'Draft'" class="flex flex-wrap gap-2">
        <button
          type="button"
          class="rounded-lg border border-slate-300 hover:bg-slate-100 text-slate-700 px-3.5 py-2 text-xs font-semibold shadow-sm transition-colors disabled:opacity-50"
          :disabled="saving"
          @click="saveCounts"
        >
          {{ saving ? 'Saving...' : '💾 Save Physical Counts' }}
        </button>

        <button
          v-if="canApproveAdjustment"
          type="button"
          class="rounded-lg bg-purple-600 hover:bg-purple-700 text-white px-4 py-2 text-xs font-semibold shadow-sm transition-colors disabled:opacity-50"
          :disabled="approving"
          @click="approveAdjustment"
        >
          {{ approving ? 'Posting ADJ Movements...' : '⚡ Approve & Post Stock Adjustment' }}
        </button>
      </div>
    </div>

    <div v-if="loading" class="p-12 text-center text-sm text-slate-500">
      Loading stock take details...
    </div>

    <div v-else-if="!stockTake" class="p-12 text-center text-sm text-red-500">
      Stock take session not found.
    </div>

    <div v-else class="space-y-6">
      <!-- Overview Card -->
      <div class="bg-white rounded-xl border border-slate-200 p-5 shadow-sm">
        <h3 class="text-xs font-bold uppercase tracking-wider text-slate-500 mb-4 border-b border-slate-100 pb-2">
          Audit Session Information
        </h3>

        <div class="grid grid-cols-1 md:grid-cols-4 gap-4 text-xs">
          <div>
            <span class="block text-slate-400 font-medium">Store / Warehouse</span>
            <span class="font-bold text-slate-800 text-sm mt-0.5 block">
              {{ stockTake.store?.name || '-' }} ({{ stockTake.store?.code }})
            </span>
            <span class="text-[11px] text-slate-500">{{ stockTake.store?.location || 'Warehouse' }}</span>
          </div>

          <div>
            <span class="block text-slate-400 font-medium">Audit Reason</span>
            <span class="font-bold text-slate-800 text-sm mt-0.5 block">
              {{ stockTake.reason }}
            </span>
          </div>

          <div>
            <span class="block text-slate-400 font-medium">Audit Status</span>
            <span class="font-bold text-slate-800 text-sm mt-0.5 block">
              {{ stockTake.status }}
            </span>
            <span v-if="stockTake.status === 'Draft'" class="text-[11px] text-amber-600 font-medium">
              Counting in progress (Inventory Unchanged)
            </span>
            <span v-else class="text-[11px] text-emerald-600 font-medium">
              Adjustment Posted & Reconciled
            </span>
          </div>

          <div>
            <span class="block text-slate-400 font-medium">Net Variance Value</span>
            <span
              class="font-mono font-bold text-sm mt-0.5 block"
              :class="netVarianceValue >= 0 ? 'text-emerald-700' : 'text-rose-700'"
            >
              {{ netVarianceValue >= 0 ? `+${formatCurrency(netVarianceValue)}` : formatCurrency(netVarianceValue) }}
            </span>
            <span class="text-[11px] text-slate-400">Total physical count valuation impact</span>
          </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-xs mt-4 pt-4 border-t border-slate-100">
          <div>
            <span class="block text-slate-400 font-medium">Initiated / Counted By</span>
            <span class="font-semibold text-slate-800">
              {{ stockTake.counter?.name || stockTake.creator?.name || '-' }}
            </span>
            <span class="text-[11px] text-slate-400 block">{{ formatDate(stockTake.counted_at || stockTake.created_at) }}</span>
          </div>

          <div v-if="stockTake.status === 'Approved'">
            <span class="block text-slate-400 font-medium">Approved / Posted By</span>
            <span class="font-semibold text-slate-800">
              {{ stockTake.approver?.name || '-' }}
            </span>
            <span class="text-[11px] text-slate-400 block">{{ formatDate(stockTake.approved_at) }}</span>
          </div>

          <div v-if="stockTake.notes" class="md:col-span-3 bg-slate-50 p-3 rounded-lg border border-slate-200">
            <span class="block text-slate-500 font-semibold mb-0.5">Notes:</span>
            <p class="text-slate-700 whitespace-pre-wrap">{{ stockTake.notes }}</p>
          </div>
        </div>
      </div>

      <!-- Items Counting & Variance Card -->
      <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
        <div class="px-5 py-4 border-b border-slate-200 flex items-center justify-between">
          <div>
            <h3 class="text-sm font-bold text-slate-900">
              Item Counts & Variance Analysis
            </h3>
            <p class="text-xs text-slate-500">
              System snapshot vs physical count
            </p>
          </div>
          <span class="text-xs text-slate-500">
            {{ stockTake.items?.length || 0 }} item(s) counted
          </span>
        </div>

        <div class="overflow-x-auto">
          <table class="w-full text-left text-xs">
            <thead class="bg-slate-50 uppercase text-slate-500 font-semibold border-b border-slate-200">
              <tr>
                <th class="px-4 py-3">#</th>
                <th class="px-4 py-3">Item / SKU</th>
                <th class="px-4 py-3">UoM</th>
                <th class="px-4 py-3 text-right">System Snapshot</th>
                <th class="px-4 py-3 text-right w-36">Physical Count</th>
                <th class="px-4 py-3 text-right">Variance Qty</th>
                <th class="px-4 py-3 text-right">Unit Cost</th>
                <th class="px-4 py-3 text-right">Variance Cost</th>
                <th class="px-4 py-3">Variance Reason / Line Notes</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <tr
                v-for="(line, idx) in stockTake.items"
                :key="line.id"
                class="hover:bg-slate-50"
                :class="lineVariance(line) !== 0 ? 'bg-amber-50/20' : ''"
              >
                <td class="px-4 py-3 text-slate-400 font-mono">{{ idx + 1 }}</td>
                <td class="px-4 py-3">
                  <div class="font-bold text-slate-900">{{ line.item?.name }}</div>
                  <div class="font-mono text-[11px] text-slate-500">{{ line.item?.sku }}</div>
                </td>
                <td class="px-4 py-3 font-semibold text-slate-600">{{ line.item?.uom }}</td>
                <td class="px-4 py-3 text-right font-mono text-slate-600">
                  {{ Number(line.system_quantity).toFixed(3) }}
                </td>
                <td class="px-4 py-3 text-right">
                  <input
                    v-if="stockTake.status === 'Draft'"
                    v-model.number="line.physical_quantity"
                    type="number"
                    step="0.001"
                    min="0"
                    class="w-full text-right rounded border border-slate-300 px-2 py-1 text-xs font-mono font-bold focus:border-purple-500 focus:outline-none"
                    @input="recalculateLine(line)"
                  >
                  <span v-else class="font-mono font-bold text-slate-900 text-sm">
                    {{ Number(line.physical_quantity).toFixed(3) }}
                  </span>
                </td>
                <td
                  class="px-4 py-3 text-right font-mono font-bold text-sm"
                  :class="varianceColorClass(lineVariance(line))"
                >
                  {{ lineVariance(line) > 0 ? `+${lineVariance(line).toFixed(3)}` : lineVariance(line).toFixed(3) }}
                </td>
                <td class="px-4 py-3 text-right font-mono text-slate-600">
                  {{ formatCurrency(line.unit_cost) }}
                </td>
                <td
                  class="px-4 py-3 text-right font-mono font-bold"
                  :class="varianceColorClass(lineVariance(line))"
                >
                  {{ formatCurrency(lineVarianceCost(line)) }}
                </td>
                <td class="px-4 py-3">
                  <input
                    v-if="stockTake.status === 'Draft'"
                    v-model="line.notes"
                    type="text"
                    placeholder="e.g. Spoilage / Found in cellar"
                    class="w-full rounded border border-slate-300 px-2 py-1 text-xs focus:border-purple-500 focus:outline-none"
                  >
                  <span v-else class="text-slate-600 text-xs">{{ line.notes || '-' }}</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Stock Ledger (ADJ) Entries Card -->
      <div v-if="stockTake.status === 'Approved'" class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
        <div class="px-5 py-4 border-b border-slate-200 flex items-center justify-between bg-slate-50">
          <div>
            <h3 class="text-sm font-bold text-slate-900">
              Stock Ledger Audit Trail (ADJ Movements)
            </h3>
            <p class="text-[11px] text-slate-500">
              Authoritative transaction log recorded atomically upon adjustment approval
            </p>
          </div>
          <span class="rounded bg-purple-100 text-purple-800 font-mono font-semibold px-2 py-0.5 text-xs">
            REF: {{ stockTake.stock_take_number }}
          </span>
        </div>

        <div v-if="!stockTake.stock_ledger_entries || stockTake.stock_ledger_entries.length === 0" class="p-6 text-center text-xs text-slate-500">
          No adjustments were necessary (All physical counts matched the system snapshot).
        </div>

        <div v-else class="overflow-x-auto">
          <table class="w-full text-left text-xs">
            <thead class="bg-slate-100 uppercase text-slate-500 font-semibold border-b border-slate-200">
              <tr>
                <th class="px-4 py-2.5">Time</th>
                <th class="px-4 py-2.5">Item</th>
                <th class="px-4 py-2.5 text-center">Type</th>
                <th class="px-4 py-2.5 text-right">Adjustment Delta</th>
                <th class="px-4 py-2.5 text-right">Balance After</th>
                <th class="px-4 py-2.5">Approved By</th>
                <th class="px-4 py-2.5">Notes</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 font-mono">
              <tr v-for="entry in stockTake.stock_ledger_entries" :key="entry.id" class="hover:bg-slate-50">
                <td class="px-4 py-2.5 text-slate-600 font-sans">{{ formatDate(entry.transaction_at) }}</td>
                <td class="px-4 py-2.5 font-bold text-slate-900 font-sans">
                  {{ entry.item?.name }} ({{ entry.item?.sku }})
                </td>
                <td class="px-4 py-2.5 text-center">
                  <span class="inline-flex rounded bg-indigo-100 text-indigo-800 px-2 py-0.5 font-semibold text-[10px]">
                    ADJ
                  </span>
                </td>
                <td
                  class="px-4 py-2.5 text-right font-bold text-sm"
                  :class="Number(entry.quantity) >= 0 ? 'text-emerald-700' : 'text-rose-700'"
                >
                  {{ Number(entry.quantity) > 0 ? `+${Number(entry.quantity).toFixed(3)}` : Number(entry.quantity).toFixed(3) }}
                </td>
                <td class="px-4 py-2.5 text-right text-slate-800 font-bold">
                  {{ Number(entry.balance_after).toFixed(3) }}
                </td>
                <td class="px-4 py-2.5 text-slate-600 font-sans">
                  {{ entry.actor?.name || '-' }}
                </td>
                <td class="px-4 py-2.5 text-slate-600 font-sans text-xs">
                  {{ entry.notes || '-' }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
const route = useRoute()
const { apiFetch } = useApi()
const { showToast } = useToast()
const { user } = useAuth()

const stockTake = ref(null)
const loading = ref(false)
const saving = ref(false)
const approving = ref(false)

const canApproveAdjustment = computed(() => {
  const roleSlug = user.value?.role?.slug
  return ['store_manager', 'admin'].includes(roleSlug)
})

const lineVariance = (line) => {
  return (Number(line.physical_quantity) || 0) - (Number(line.system_quantity) || 0)
}

const lineVarianceCost = (line) => {
  return lineVariance(line) * (Number(line.unit_cost) || 0)
}

const netVarianceValue = computed(() => {
  if (!stockTake.value?.items) return 0
  return stockTake.value.items.reduce((sum, line) => sum + lineVarianceCost(line), 0)
})

const varianceColorClass = (variance) => {
  if (variance > 0) return 'text-emerald-700'
  if (variance < 0) return 'text-rose-700'
  return 'text-slate-400'
}

const recalculateLine = (line) => {
  line.variance_quantity = lineVariance(line)
  line.variance_cost = lineVarianceCost(line)
}

const formatDate = (dateStr) => {
  if (!dateStr) return '-'
  return new Date(dateStr).toLocaleString()
}

const formatCurrency = (val) => {
  const num = Number(val) || 0
  return num.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

const fetchStockTake = async () => {
  loading.value = true
  try {
    const res = await apiFetch(`/inventory/stock-takes/${route.params.id}`)
    stockTake.value = res.data
  } catch (e) {
    showToast(e.data?.message || 'Failed to load stock take details', 'error')
  } finally {
    loading.value = false
  }
}

const saveCounts = async () => {
  saving.value = true
  try {
    const payloadItems = (stockTake.value.items || []).map(line => ({
      item_id: line.item_id,
      physical_quantity: Number(line.physical_quantity) || 0,
      notes: line.notes || null
    }))

    const res = await apiFetch(`/inventory/stock-takes/${route.params.id}`, {
      method: 'PUT',
      body: {
        reason: stockTake.value.reason,
        notes: stockTake.value.notes,
        items: payloadItems
      }
    })

    showToast('Physical counts saved successfully', 'success')
    stockTake.value = res.data
  } catch (e) {
    showToast(e.data?.message || 'Failed to save counts', 'error')
  } finally {
    saving.value = false
  }
}

const approveAdjustment = async () => {
  if (!confirm('Are you sure you want to approve this stock adjustment? This will post ADJ movements to the Stock Ledger and permanently align Current Stock balances to the physical counts.')) {
    return
  }

  approving.value = true
  try {
    const res = await apiFetch(`/inventory/stock-takes/${route.params.id}/approve`, {
      method: 'POST'
    })

    showToast(res.message || 'Stock adjustment approved and posted successfully', 'success')
    stockTake.value = res.data
  } catch (e) {
    showToast(e.data?.message || 'Failed to approve stock adjustment', 'error')
  } finally {
    approving.value = false
  }
}

onMounted(() => {
  fetchStockTake()
})
</script>
