<template>
  <div class="space-y-5">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
      <div>
        <h2 class="text-xl font-bold text-slate-900">Suppliers Directory</h2>
        <p class="text-xs text-slate-500 mt-0.5">
          Manage hotel suppliers, contact persons, and active vendor status
        </p>
      </div>
      <button
        v-if="canManage"
        type="button"
        class="rounded-lg bg-amber-500 hover:bg-amber-600 text-slate-950 font-semibold px-4 py-2 text-sm shadow-sm"
        @click="openCreateModal"
      >
        + New Supplier
      </button>
    </div>

    <!-- Filters Bar -->
    <div class="bg-white rounded-xl border border-slate-200 p-4 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3">
      <div class="lg:col-span-2">
        <label class="block text-xs font-medium text-slate-600 mb-1">Search</label>
        <input
          v-model="filters.search"
          type="text"
          placeholder="Search code, name, contact, email..."
          class="w-full rounded-lg border border-slate-300 px-3 py-1.5 text-sm"
          @keyup.enter="loadSuppliers(1)"
        />
      </div>

      <div>
        <label class="block text-xs font-medium text-slate-600 mb-1">Status</label>
        <select
          v-model="filters.is_active"
          class="w-full rounded-lg border border-slate-300 px-3 py-1.5 text-sm bg-white"
          @change="loadSuppliers(1)"
        >
          <option value="">All Statuses</option>
          <option value="1">Active Only</option>
          <option value="0">Inactive Only</option>
        </select>
      </div>

      <div>
        <label class="block text-xs font-medium text-slate-600 mb-1">Sort By</label>
        <select
          v-model="filters.sort_by"
          class="w-full rounded-lg border border-slate-300 px-3 py-1.5 text-sm bg-white"
          @change="loadSuppliers(1)"
        >
          <option value="code">Code</option>
          <option value="name">Supplier Name</option>
          <option value="contact_person">Contact Person</option>
          <option value="created_at">Created Date</option>
        </select>
      </div>

      <div class="flex items-end gap-2">
        <div class="flex-1">
          <label class="block text-xs font-medium text-slate-600 mb-1">Order</label>
          <select
            v-model="filters.sort_dir"
            class="w-full rounded-lg border border-slate-300 px-3 py-1.5 text-sm bg-white"
            @change="loadSuppliers(1)"
          >
            <option value="asc">Ascending</option>
            <option value="desc">Descending</option>
          </select>
        </div>
        <button
          type="button"
          class="rounded-lg bg-slate-900 hover:bg-slate-800 text-white px-3.5 py-1.5 text-sm font-medium"
          @click="loadSuppliers(1)"
        >
          Filter
        </button>
      </div>
    </div>

    <!-- Error State -->
    <div
      v-if="loadError"
      class="rounded-lg bg-red-50 border border-red-200 p-4 text-sm text-red-700"
    >
      {{ loadError }}
    </div>

    <!-- Data Table -->
    <div class="bg-white rounded-xl border border-slate-200 overflow-hidden shadow-sm">
      <div v-if="loading" class="p-8 text-center text-sm text-slate-500">
        Loading suppliers...
      </div>

      <div v-else-if="suppliers.length === 0" class="p-8 text-center text-sm text-slate-500">
        No suppliers found matching the current filters.
      </div>

      <div v-else class="overflow-x-auto">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
          <thead class="bg-slate-50 text-xs font-semibold uppercase text-slate-600">
            <tr>
              <th class="px-4 py-3 text-left">Code</th>
              <th class="px-4 py-3 text-left">Supplier Name</th>
              <th class="px-4 py-3 text-left">Contact Person</th>
              <th class="px-4 py-3 text-left">Phone / Email</th>
              <th class="px-4 py-3 text-left">Status</th>
              <th v-if="canManage" class="px-4 py-3 text-right">Actions</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-200">
            <tr v-for="sup in suppliers" :key="sup.id" class="hover:bg-slate-50">
              <td class="px-4 py-3 font-mono text-xs font-semibold text-slate-900">
                {{ sup.code }}
              </td>
              <td class="px-4 py-3">
                <div class="font-medium text-slate-900">{{ sup.name }}</div>
                <div v-if="sup.address" class="text-xs text-slate-500 truncate max-w-xs">
                  {{ sup.address }}
                </div>
              </td>
              <td class="px-4 py-3 text-slate-700">
                {{ sup.contact_person || '—' }}
              </td>
              <td class="px-4 py-3 text-xs text-slate-600">
                <div>{{ sup.phone || '—' }}</div>
                <div class="text-slate-400">{{ sup.email || '' }}</div>
              </td>
              <td class="px-4 py-3">
                <StatusBadge :active="Boolean(sup.is_active)" />
              </td>
              <td v-if="canManage" class="px-4 py-3 text-right space-x-2">
                <button
                  type="button"
                  class="text-xs font-semibold text-blue-600 hover:text-blue-800"
                  @click="openEditModal(sup)"
                >
                  Edit
                </button>
                <button
                  v-if="sup.is_active"
                  type="button"
                  :disabled="busyId === sup.id"
                  class="text-xs font-semibold text-amber-600 hover:text-amber-800 disabled:opacity-50"
                  @click="handleDeactivate(sup)"
                >
                  Deactivate
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <PaginationBar :meta="paginationMeta" @change="loadSuppliers" />
    </div>

    <!-- Create / Edit Modal -->
    <div
      v-if="showModal"
      class="fixed inset-0 z-40 flex items-center justify-center bg-slate-950/60 p-4"
    >
      <div class="w-full max-w-lg rounded-xl bg-white shadow-xl border border-slate-200 overflow-hidden">
        <div class="px-6 py-4 bg-slate-900 text-white flex items-center justify-between">
          <h3 class="text-base font-bold">
            {{ editingId ? 'Edit Supplier' : 'Create Supplier' }}
          </h3>
          <button type="button" class="text-slate-400 hover:text-white" @click="closeModal">
            ✕
          </button>
        </div>

        <form class="p-6 space-y-4" @submit.prevent="saveSupplier">
          <div v-if="formError" class="rounded-lg bg-red-50 border border-red-200 p-3 text-xs text-red-700">
            {{ formError }}
          </div>

          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label class="block text-xs font-semibold text-slate-700 mb-1">Supplier Code *</label>
              <input
                v-model="form.code"
                type="text"
                required
                class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm uppercase"
                placeholder="SUP-004"
              />
              <p v-if="fieldErrors.code" class="mt-1 text-xs text-red-600">
                {{ fieldErrors.code[0] }}
              </p>
            </div>

            <div>
              <label class="block text-xs font-semibold text-slate-700 mb-1">Contact Person</label>
              <input
                v-model="form.contact_person"
                type="text"
                class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                placeholder="U Aung Ko"
              />
              <p v-if="fieldErrors.contact_person" class="mt-1 text-xs text-red-600">
                {{ fieldErrors.contact_person[0] }}
              </p>
            </div>
          </div>

          <div>
            <label class="block text-xs font-semibold text-slate-700 mb-1">Supplier Name *</label>
            <input
              v-model="form.name"
              type="text"
              required
              class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
              placeholder="Golden Star Hospitality Supplies"
            />
            <p v-if="fieldErrors.name" class="mt-1 text-xs text-red-600">
              {{ fieldErrors.name[0] }}
            </p>
          </div>

          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label class="block text-xs font-semibold text-slate-700 mb-1">Email</label>
              <input
                v-model="form.email"
                type="email"
                class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                placeholder="sales@supplier.local"
              />
              <p v-if="fieldErrors.email" class="mt-1 text-xs text-red-600">
                {{ fieldErrors.email[0] }}
              </p>
            </div>

            <div>
              <label class="block text-xs font-semibold text-slate-700 mb-1">Phone</label>
              <input
                v-model="form.phone"
                type="text"
                class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                placeholder="+95-9-5001234"
              />
              <p v-if="fieldErrors.phone" class="mt-1 text-xs text-red-600">
                {{ fieldErrors.phone[0] }}
              </p>
            </div>
          </div>

          <div>
            <label class="block text-xs font-semibold text-slate-700 mb-1">Address</label>
            <textarea
              v-model="form.address"
              rows="2"
              class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
              placeholder="Street, Township, City"
            ></textarea>
            <p v-if="fieldErrors.address" class="mt-1 text-xs text-red-600">
              {{ fieldErrors.address[0] }}
            </p>
          </div>

          <div class="flex items-center gap-2">
            <input
              id="sup_active"
              v-model="form.is_active"
              type="checkbox"
              class="rounded border-slate-300 text-amber-500"
            />
            <label for="sup_active" class="text-xs font-medium text-slate-700">
              Active Supplier (available for new Purchase Orders)
            </label>
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
              {{ submitting ? 'Saving...' : 'Save Supplier' }}
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

const canManage = computed(() => hasRole(['admin', 'procurement_officer']))

const suppliers = ref([])
const loading = ref(false)
const loadError = ref('')
const busyId = ref(null)

const filters = reactive({
  search: '',
  is_active: '',
  sort_by: 'code',
  sort_dir: 'asc'
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
  code: '',
  name: '',
  contact_person: '',
  email: '',
  phone: '',
  address: '',
  is_active: true
})

const loadSuppliers = async (page = 1) => {
  loading.value = true
  loadError.value = ''

  try {
    const query = {
      page,
      per_page: 10,
      sort_by: filters.sort_by,
      sort_dir: filters.sort_dir
    }
    if (filters.search) query.search = filters.search
    if (filters.is_active !== '') query.is_active = filters.is_active

    const res = await apiFetch('/suppliers', { query })
    suppliers.value = res.data || []
    paginationMeta.value = {
      current_page: res.current_page || 1,
      last_page: res.last_page || 1,
      total: res.total || 0,
      from: res.from || 0,
      to: res.to || 0
    }
  } catch (err) {
    const parsed = extractApiError(err, 'Failed to load suppliers.')
    loadError.value = parsed.message
  } finally {
    loading.value = false
  }
}

const openCreateModal = () => {
  editingId.value = null
  form.code = ''
  form.name = ''
  form.contact_person = ''
  form.email = ''
  form.phone = ''
  form.address = ''
  form.is_active = true
  formError.value = ''
  fieldErrors.value = {}
  showModal.value = true
}

const openEditModal = (sup) => {
  editingId.value = sup.id
  form.code = sup.code
  form.name = sup.name
  form.contact_person = sup.contact_person || ''
  form.email = sup.email || ''
  form.phone = sup.phone || ''
  form.address = sup.address || ''
  form.is_active = Boolean(sup.is_active)
  formError.value = ''
  fieldErrors.value = {}
  showModal.value = true
}

const closeModal = () => {
  showModal.value = false
}

const saveSupplier = async () => {
  if (submitting.value) return
  submitting.value = true
  formError.value = ''
  fieldErrors.value = {}

  try {
    if (editingId.value) {
      await apiFetch(`/suppliers/${editingId.value}`, {
        method: 'PUT',
        body: { ...form }
      })
      showToast('Supplier updated successfully.')
    } else {
      await apiFetch('/suppliers', {
        method: 'POST',
        body: { ...form }
      })
      showToast('Supplier created successfully.')
    }
    closeModal()
    await loadSuppliers(paginationMeta.value.current_page)
  } catch (err) {
    const parsed = extractApiError(err, 'Unable to save supplier.')
    formError.value = parsed.message
    fieldErrors.value = parsed.fieldErrors
  } finally {
    submitting.value = false
  }
}

const handleDeactivate = async (sup) => {
  if (busyId.value) return
  busyId.value = sup.id
  try {
    await apiFetch(`/suppliers/${sup.id}/deactivate`, { method: 'PATCH' })
    showToast(`Supplier ${sup.code} deactivated.`)
    await loadSuppliers(paginationMeta.value.current_page)
  } catch (err) {
    const parsed = extractApiError(err, 'Failed to deactivate supplier.')
    showToast(parsed.message, 'error')
  } finally {
    busyId.value = null
  }
}

onMounted(() => {
  loadSuppliers(1)
})
</script>
