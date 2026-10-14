<template>
  <div class="space-y-6">
    <div class="flex items-center justify-between">
      <NuxtLink
        to="/requisitions"
        class="text-xs font-semibold text-slate-600 hover:text-slate-900"
      >
        ← Back to Requisitions List
      </NuxtLink>
    </div>

    <div v-if="loading" class="bg-white rounded-xl border border-slate-200 p-8 text-center text-sm text-slate-500">
      Loading requisition details...
    </div>

    <div
      v-else-if="loadError"
      class="rounded-xl bg-red-50 border border-red-200 p-5 text-sm text-red-700"
    >
      {{ loadError }}
    </div>

    <template v-else-if="requisition">
      <!-- Header Summary Card -->
      <div class="bg-white rounded-xl border border-slate-200 p-6 shadow-sm space-y-4">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 border-b border-slate-200 pb-4">
          <div>
            <div class="flex items-center gap-2.5">
              <span
                class="inline-flex rounded px-2.5 py-0.5 text-xs font-bold"
                :class="
                  requisition.type === 'SR'
                    ? 'bg-indigo-100 text-indigo-800'
                    : 'bg-purple-100 text-purple-800'
                "
              >
                {{ requisition.type === 'SR' ? 'Store Requisition (SR)' : 'Purchase Requisition (PR)' }}
              </span>
              <StatusBadge :status="requisition.status" />
            </div>
            <h2 class="text-xl font-bold text-slate-900 mt-1.5 font-mono">
              {{ requisition.requisition_number }}
            </h2>
          </div>

          <!-- Authorized Workflow Action Buttons -->
          <div class="flex flex-wrap items-center gap-2">
            <button
              v-if="canHodApprove"
              type="button"
              :disabled="actionLoading"
              class="rounded-lg bg-emerald-600 hover:bg-emerald-700 disabled:opacity-50 text-white font-semibold px-4 py-2 text-xs shadow-sm"
              @click="handleHodApprove"
            >
              {{ actionLoading ? 'Processing...' : 'Approve as HOD' }}
            </button>

            <button
              v-if="canGmApprove"
              type="button"
              :disabled="actionLoading"
              class="rounded-lg bg-emerald-600 hover:bg-emerald-700 disabled:opacity-50 text-white font-semibold px-4 py-2 text-xs shadow-sm"
              @click="handleGmApprove"
            >
              {{ actionLoading ? 'Processing...' : 'Approve as GM' }}
            </button>

            <button
              v-if="canReject"
              type="button"
              :disabled="actionLoading"
              class="rounded-lg bg-red-600 hover:bg-red-700 disabled:opacity-50 text-white font-semibold px-4 py-2 text-xs shadow-sm"
              @click="showRejectBox = !showRejectBox"
            >
              Reject Requisition
            </button>

            <!-- Quick Cross-Module Workflow Shortcuts -->
            <NuxtLink
              v-if="requisition.type === 'PR' && requisition.status === 'GM Approved'"
              to="/purchase-orders"
              class="rounded-lg bg-amber-500 hover:bg-amber-600 text-slate-950 font-semibold px-4 py-2 text-xs shadow-sm inline-flex items-center gap-1"
            >
              <span>+ Generate Purchase Order</span>
            </NuxtLink>

            <NuxtLink
              v-if="requisition.type === 'SR' && ['HOD Approved', 'Partially Issued'].includes(requisition.status)"
              to="/stock-issues"
              class="rounded-lg bg-rose-600 hover:bg-rose-700 text-white font-semibold px-4 py-2 text-xs shadow-sm inline-flex items-center gap-1"
            >
              <span>+ Issue Stock (Stock OUT)</span>
            </NuxtLink>
          </div>
        </div>

        <!-- Rejection Reason Box -->
        <div
          v-if="showRejectBox && canReject"
          class="rounded-lg bg-red-50 border border-red-200 p-4 space-y-3"
        >
          <label class="block text-xs font-bold text-red-800">
            Rejection Reason (Required) *
          </label>
          <textarea
            v-model="rejectionReason"
            rows="2"
            class="w-full rounded-lg border border-red-300 px-3 py-2 text-sm bg-white"
            placeholder="Provide clear explanation for rejecting this requisition..."
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

        <!-- Metadata Grid -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 text-xs">
          <div class="p-3 rounded-lg bg-slate-50 border border-slate-100">
            <div class="text-slate-500 font-medium">Department / Cost Center</div>
            <div class="text-slate-900 font-semibold mt-0.5">
              {{ requisition.department?.code }} — {{ requisition.department?.name }}
            </div>
          </div>

          <div class="p-3 rounded-lg bg-slate-50 border border-slate-100">
            <div class="text-slate-500 font-medium">Target / Source Store</div>
            <div class="text-slate-900 font-semibold mt-0.5">
              {{ requisition.store ? `${requisition.store.code} — ${requisition.store.name}` : 'N/A (Direct Purchase)' }}
            </div>
          </div>

          <div class="p-3 rounded-lg bg-slate-50 border border-slate-100">
            <div class="text-slate-500 font-medium">Requested By</div>
            <div class="text-slate-900 font-semibold mt-0.5">
              {{ requisition.requester?.name }}
            </div>
            <div class="text-slate-400">{{ formatDate(requisition.created_at) }}</div>
          </div>

          <div class="p-3 rounded-lg bg-slate-50 border border-slate-100">
            <div class="text-slate-500 font-medium">Allowed Workflow</div>
            <div class="text-slate-800 font-medium mt-0.5">
              {{
                requisition.type === 'SR'
                  ? 'Pending → HOD Approved → Issued'
                  : 'Pending → HOD Approved → GM Approved → PO Generated'
              }}
            </div>
          </div>
        </div>

        <div v-if="requisition.notes" class="text-xs text-slate-700 bg-slate-50 p-3 rounded-lg border border-slate-100">
          <span class="font-semibold text-slate-900">Notes:</span> {{ requisition.notes }}
        </div>
      </div>

      <!-- Line Items Card -->
      <div class="bg-white rounded-xl border border-slate-200 overflow-hidden shadow-sm">
        <div class="px-6 py-4 border-b border-slate-200 font-bold text-sm text-slate-900">
          Requisition Line Items
        </div>
        <div class="overflow-x-auto">
          <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-xs font-semibold uppercase text-slate-600">
              <tr>
                <th class="px-4 py-3 text-left">SKU</th>
                <th class="px-4 py-3 text-left">Item Name</th>
                <th class="px-4 py-3 text-left">UoM</th>
                <th class="px-4 py-3 text-right">Requested Qty</th>
                <th v-if="requisition.type === 'SR'" class="px-4 py-3 text-right">Issued Qty</th>
                <th class="px-4 py-3 text-left">Line Notes</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-200">
              <tr v-for="li in requisition.items" :key="li.id">
                <td class="px-4 py-3 font-mono text-xs font-semibold text-slate-900">
                  {{ li.item?.sku || '—' }}
                </td>
                <td class="px-4 py-3 font-medium text-slate-900">
                  {{ li.item?.name || '—' }}
                </td>
                <td class="px-4 py-3 font-mono text-xs text-slate-700">
                  {{ li.uom }}
                </td>
                <td class="px-4 py-3 text-right font-mono text-xs font-bold text-slate-900">
                  {{ li.requested_quantity }}
                </td>
                <td v-if="requisition.type === 'SR'" class="px-4 py-3 text-right font-mono text-xs text-slate-700">
                  {{ li.issued_quantity }}
                </td>
                <td class="px-4 py-3 text-xs text-slate-500">
                  {{ li.notes || '—' }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Approval & Audit History Card -->
      <div class="bg-white rounded-xl border border-slate-200 p-6 shadow-sm space-y-3">
        <h3 class="text-sm font-bold text-slate-900">Approval & Audit Trail</h3>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 text-xs">
          <div class="p-3.5 rounded-lg border border-slate-200">
            <div class="font-semibold text-slate-700">1. HOD Approval Stage</div>
            <div v-if="requisition.hod_approver" class="mt-1 text-emerald-700 font-medium">
              Approved by {{ requisition.hod_approver.name }}
              <div class="text-slate-400">{{ formatDate(requisition.hod_approved_at) }}</div>
            </div>
            <div v-else class="mt-1 text-slate-400">
              Awaiting Department HOD approval
            </div>
          </div>

          <div v-if="requisition.type === 'PR'" class="p-3.5 rounded-lg border border-slate-200">
            <div class="font-semibold text-slate-700">2. GM Approval Stage (PR Only)</div>
            <div v-if="requisition.gm_approver" class="mt-1 text-emerald-700 font-medium">
              Approved by {{ requisition.gm_approver.name }}
              <div class="text-slate-400">{{ formatDate(requisition.gm_approved_at) }}</div>
            </div>
            <div v-else class="mt-1 text-slate-400">
              Awaiting General Manager approval
            </div>
          </div>

          <div v-if="requisition.status === 'Rejected'" class="p-3.5 rounded-lg border border-red-200 bg-red-50">
            <div class="font-semibold text-red-800">Rejected</div>
            <div class="mt-1 text-red-700 font-medium">
              By {{ requisition.rejector?.name || 'Approver' }} ({{ formatDate(requisition.rejected_at) }})
            </div>
            <div class="mt-1 text-red-900">
              Reason: {{ requisition.rejection_reason }}
            </div>
          </div>
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

const requisition = ref(null)
const loading = ref(false)
const loadError = ref('')
const actionLoading = ref(false)
const showRejectBox = ref(false)
const rejectionReason = ref('')

const isSelfRequester = computed(() => {
  if (!user.value || !requisition.value) return false
  return Number(user.value.id) === Number(requisition.value.requester_id)
})

const canHodApprove = computed(() => {
  if (!requisition.value || !user.value) return false
  if (isSelfRequester.value) return false
  return (
    requisition.value.status === 'Pending' &&
    hasRole('hod') &&
    Number(user.value.department_id) === Number(requisition.value.department_id)
  )
})

const canGmApprove = computed(() => {
  if (!requisition.value || !user.value) return false
  if (isSelfRequester.value) return false
  return (
    requisition.value.type === 'PR' &&
    requisition.value.status === 'HOD Approved' &&
    hasRole('gm')
  )
})

const canReject = computed(() => canHodApprove.value || canGmApprove.value)

const formatDate = (iso) => {
  if (!iso) return '—'
  return new Date(iso).toLocaleString()
}

const loadDetail = async () => {
  loading.value = true
  loadError.value = ''

  try {
    const res = await apiFetch(`/requisitions/${route.params.id}`)
    requisition.value = res.data || null
  } catch (err) {
    const parsed = extractApiError(err, 'Unable to load requisition details.')
    loadError.value = parsed.message
  } finally {
    loading.value = false
  }
}

const handleHodApprove = async () => {
  if (actionLoading.value || !requisition.value) return
  actionLoading.value = true
  try {
    const res = await apiFetch(`/requisitions/${requisition.value.id}/hod-approve`, {
      method: 'POST'
    })
    requisition.value = res.data
    showToast('Requisition approved by HOD.')
  } catch (err) {
    const parsed = extractApiError(err, 'Failed to approve requisition.')
    showToast(parsed.message, 'error')
  } finally {
    actionLoading.value = false
  }
}

const handleGmApprove = async () => {
  if (actionLoading.value || !requisition.value) return
  actionLoading.value = true
  try {
    const res = await apiFetch(`/requisitions/${requisition.value.id}/gm-approve`, {
      method: 'POST'
    })
    requisition.value = res.data
    showToast('Purchase Requisition approved by GM.')
  } catch (err) {
    const parsed = extractApiError(err, 'Failed to approve PR as GM.')
    showToast(parsed.message, 'error')
  } finally {
    actionLoading.value = false
  }
}

const handleReject = async () => {
  if (actionLoading.value || !requisition.value || !rejectionReason.value.trim()) return
  actionLoading.value = true
  try {
    const res = await apiFetch(`/requisitions/${requisition.value.id}/reject`, {
      method: 'POST',
      body: { rejection_reason: rejectionReason.value }
    })
    requisition.value = res.data
    showRejectBox.value = false
    rejectionReason.value = ''
    showToast('Requisition rejected.')
  } catch (err) {
    const parsed = extractApiError(err, 'Failed to reject requisition.')
    showToast(parsed.message, 'error')
  } finally {
    actionLoading.value = false
  }
}

onMounted(() => {
  loadDetail()
})
</script>
