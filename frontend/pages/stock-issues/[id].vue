<template>
  <div class="space-y-6 max-w-6xl mx-auto">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
      <div>
        <NuxtLink
          to="/stock-issues"
          class="text-xs font-semibold text-rose-600 hover:text-rose-800 flex items-center gap-1 mb-1"
        >
          &larr; Back to Bulk Stock Issues
        </NuxtLink>
        <div class="flex items-center gap-3">
          <h2 class="text-xl font-bold text-slate-900 font-mono">
            {{ issue?.issue_number || 'Loading...' }}
          </h2>
          <StatusBadge v-if="issue" :status="issue.status" />
        </div>
      </div>

      <!-- Action Button for Draft Issue -->
      <div v-if="issue && issue.status === 'Draft' && canPostIssue" class="flex gap-2">
        <button
          type="button"
          class="rounded-lg bg-rose-600 hover:bg-rose-700 text-white px-4 py-2 text-xs font-semibold shadow-sm transition-colors disabled:opacity-50"
          :disabled="posting"
          @click="postIssue"
        >
          {{ posting ? 'Posting Stock OUT...' : 'Post Stock Issue (Deduct Stock OUT)' }}
        </button>
      </div>
    </div>

    <div v-if="loading" class="p-12 text-center text-sm text-slate-500">
      Loading stock issue details...
    </div>

    <div v-else-if="!issue" class="p-12 text-center text-sm text-red-500">
      Stock issue not found.
    </div>

    <div v-else class="space-y-6">
      <!-- Overview Card -->
      <div class="bg-white rounded-xl border border-slate-200 p-5 shadow-sm">
        <h3 class="text-xs font-bold uppercase tracking-wider text-slate-500 mb-4 border-b border-slate-100 pb-2">
          Document Overview & Cost Attribution
        </h3>

        <div class="grid grid-cols-1 md:grid-cols-4 gap-4 text-xs">
          <div>
            <span class="block text-slate-400 font-medium">Source Store</span>
            <span class="font-bold text-slate-800 text-sm mt-0.5 block">
              {{ issue.store?.name || '-' }} ({{ issue.store?.code }})
            </span>
            <span class="text-[11px] text-slate-500">{{ issue.store?.location || 'Warehouse' }}</span>
          </div>

          <div>
            <span class="block text-slate-400 font-medium">Receiving Department / Cost Center</span>
            <span class="font-bold text-slate-800 text-sm mt-0.5 block">
              {{ issue.department?.name || '-' }} ({{ issue.department?.code }})
            </span>
            <span class="text-[11px] text-rose-600 font-medium">Cost Center Allocation</span>
          </div>

          <div>
            <span class="block text-slate-400 font-medium">Originating Store Requisition</span>
            <NuxtLink
              v-if="issue.requisition"
              :to="`/requisitions/${issue.requisition.id}`"
              class="font-mono font-bold text-rose-600 hover:underline text-sm mt-0.5 block"
            >
              {{ issue.requisition.requisition_number }}
            </NuxtLink>
            <span class="text-[11px] text-slate-500">Status: {{ issue.requisition?.status }}</span>
          </div>

          <div>
            <span class="block text-slate-400 font-medium">Posting Status</span>
            <span class="font-bold text-slate-800 text-sm mt-0.5 block">
              {{ issue.status }}
            </span>
            <span v-if="issue.status === 'Posted'" class="text-[11px] text-emerald-600 font-medium">
              Posted at {{ formatDate(issue.issued_at) }}
            </span>
            <span v-else class="text-[11px] text-amber-600 font-medium">
              Draft (Inventory Unchanged)
            </span>
          </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-xs mt-4 pt-4 border-t border-slate-100">
          <div>
            <span class="block text-slate-400 font-medium">Created By</span>
            <span class="font-semibold text-slate-800">
              {{ issue.creator?.name || '-' }}
            </span>
            <span class="text-[11px] text-slate-400 block">{{ formatDate(issue.created_at) }}</span>
          </div>

          <div v-if="issue.status === 'Posted'">
            <span class="block text-slate-400 font-medium">Issued / Posted By</span>
            <span class="font-semibold text-slate-800">
              {{ issue.issuer?.name || '-' }}
            </span>
            <span class="text-[11px] text-slate-400 block">{{ formatDate(issue.issued_at) }}</span>
          </div>

          <div v-if="issue.notes" class="md:col-span-2 bg-slate-50 p-3 rounded-lg border border-slate-200">
            <span class="block text-slate-500 font-semibold mb-0.5">Notes / Purpose:</span>
            <p class="text-slate-700 whitespace-pre-wrap">{{ issue.notes }}</p>
          </div>
        </div>
      </div>

      <!-- Line Items Card -->
      <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
        <div class="px-5 py-4 border-b border-slate-200 flex items-center justify-between">
          <h3 class="text-sm font-bold text-slate-900">
            Issued Line Items
          </h3>
          <span class="text-xs text-slate-500">
            {{ issue.items?.length || 0 }} line item(s)
          </span>
        </div>

        <div class="overflow-x-auto">
          <table class="w-full text-left text-xs">
            <thead class="bg-slate-50 uppercase text-slate-500 font-semibold border-b border-slate-200">
              <tr>
                <th class="px-4 py-3">#</th>
                <th class="px-4 py-3">Item / SKU</th>
                <th class="px-4 py-3">UoM</th>
                <th class="px-4 py-3 text-right">SR Requested</th>
                <th class="px-4 py-3 text-right">Issued Quantity</th>
                <th class="px-4 py-3 text-right">Unit Cost</th>
                <th class="px-4 py-3 text-right">Line Total Cost</th>
                <th class="px-4 py-3 text-right">Store Balance</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <tr v-for="(line, idx) in issue.items" :key="line.id" class="hover:bg-slate-50">
                <td class="px-4 py-3 text-slate-400 font-mono">{{ idx + 1 }}</td>
                <td class="px-4 py-3">
                  <div class="font-bold text-slate-900">{{ line.item?.name || 'Item' }}</div>
                  <div class="font-mono text-[11px] text-slate-500">{{ line.item?.sku }}</div>
                </td>
                <td class="px-4 py-3 font-semibold text-slate-600">{{ line.uom }}</td>
                <td class="px-4 py-3 text-right font-mono text-slate-600">
                  {{ Number(line.requisition_item?.requested_quantity || 0).toFixed(3) }}
                </td>
                <td class="px-4 py-3 text-right font-mono font-bold text-rose-700 text-sm">
                  {{ Number(line.issued_quantity).toFixed(3) }}
                </td>
                <td class="px-4 py-3 text-right font-mono text-slate-600">
                  {{ formatCurrency(line.unit_cost) }}
                </td>
                <td class="px-4 py-3 text-right font-mono font-bold text-slate-900">
                  {{ formatCurrency(line.line_cost) }}
                </td>
                <td class="px-4 py-3 text-right font-mono text-slate-600">
                  {{ line.available_stock !== undefined ? Number(line.available_stock).toFixed(3) : '-' }}
                </td>
              </tr>
            </tbody>
            <tfoot class="bg-slate-50 border-t border-slate-200 font-semibold">
              <tr>
                <td colspan="6" class="px-4 py-3 text-right text-slate-700">Total Department Cost Allocation:</td>
                <td class="px-4 py-3 text-right text-slate-900 font-mono text-sm font-bold">
                  {{ formatCurrency(totalCost) }}
                </td>
                <td></td>
              </tr>
            </tfoot>
          </table>
        </div>
      </div>

      <!-- Stock Ledger OUT Entries Card -->
      <div v-if="issue.status === 'Posted'" class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
        <div class="px-5 py-4 border-b border-slate-200 flex items-center justify-between bg-slate-50">
          <div>
            <h3 class="text-sm font-bold text-slate-900">
              Stock Ledger Audit Trail (OUT Movements)
            </h3>
            <p class="text-[11px] text-slate-500">
              Authoritative transaction log generated atomically upon issue posting
            </p>
          </div>
          <span class="rounded bg-rose-100 text-rose-800 font-mono font-semibold px-2 py-0.5 text-xs">
            REF: {{ issue.issue_number }}
          </span>
        </div>

        <div v-if="!issue.stock_ledger_entries || issue.stock_ledger_entries.length === 0" class="p-6 text-center text-xs text-slate-400">
          No ledger movements recorded.
        </div>

        <div v-else class="overflow-x-auto">
          <table class="w-full text-left text-xs">
            <thead class="bg-slate-100 uppercase text-slate-500 font-semibold border-b border-slate-200">
              <tr>
                <th class="px-4 py-2.5">Time</th>
                <th class="px-4 py-2.5">Item</th>
                <th class="px-4 py-2.5">Type</th>
                <th class="px-4 py-2.5 text-right">Delta Qty</th>
                <th class="px-4 py-2.5 text-right">Balance After</th>
                <th class="px-4 py-2.5">Cost Center</th>
                <th class="px-4 py-2.5">Posted By</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 font-mono">
              <tr v-for="entry in issue.stock_ledger_entries" :key="entry.id" class="hover:bg-slate-50">
                <td class="px-4 py-2.5 text-slate-600 font-sans">{{ formatDate(entry.transaction_at) }}</td>
                <td class="px-4 py-2.5 font-bold text-slate-900 font-sans">
                  {{ entry.item?.name }} ({{ entry.item?.sku }})
                </td>
                <td class="px-4 py-2.5">
                  <span class="inline-flex rounded bg-rose-100 text-rose-800 px-1.5 py-0.5 font-semibold text-[10px]">
                    {{ entry.transaction_type }}
                  </span>
                </td>
                <td class="px-4 py-2.5 text-right font-bold text-rose-700">
                  {{ Number(entry.quantity).toFixed(3) }}
                </td>
                <td class="px-4 py-2.5 text-right text-slate-700">
                  {{ Number(entry.balance_after).toFixed(3) }}
                </td>
                <td class="px-4 py-2.5 text-slate-700 font-sans">
                  {{ entry.department?.name || issue.department?.name || '-' }}
                </td>
                <td class="px-4 py-2.5 text-slate-600 font-sans">
                  {{ entry.actor?.name || '-' }}
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

const issue = ref(null)
const loading = ref(false)
const posting = ref(false)

const canPostIssue = computed(() => {
  const roleSlug = user.value?.role?.slug
  return ['store_keeper', 'store_manager', 'admin'].includes(roleSlug)
})

const totalCost = computed(() => {
  if (!issue.value?.items) return 0
  return issue.value.items.reduce((sum, item) => sum + (Number(item.line_cost) || 0), 0)
})

const formatDate = (dateStr) => {
  if (!dateStr) return '-'
  return new Date(dateStr).toLocaleString()
}

const formatCurrency = (val) => {
  const num = Number(val) || 0
  return num.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

const fetchIssue = async () => {
  loading.value = true
  try {
    const res = await apiFetch(`/stock-issues/${route.params.id}`)
    issue.value = res.data
  } catch (e) {
    showToast(e.data?.message || 'Failed to load stock issue', 'error')
  } finally {
    loading.value = false
  }
}

const postIssue = async () => {
  if (!confirm('Are you sure you want to post this Stock Issue? This will immediately deduct stock from the warehouse store and record Stock OUT movements.')) {
    return
  }

  posting.value = true
  try {
    const res = await apiFetch(`/stock-issues/${route.params.id}/post`, {
      method: 'POST'
    })
    showToast(res.message || 'Stock Issue posted successfully', 'success')
    issue.value = res.data
  } catch (e) {
    showToast(e.data?.message || 'Failed to post stock issue', 'error')
  } finally {
    posting.value = false
  }
}

onMounted(() => {
  fetchIssue()
})
</script>
