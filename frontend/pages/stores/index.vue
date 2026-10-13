<template>
  <div class="space-y-5">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
      <div>
        <h2 class="text-xl font-bold text-slate-900">Stores / Warehouses</h2>
        <p class="text-xs text-slate-500 mt-0.5">
          Manage central hotel stores, beverage cellars, and housekeeping sub-stores
        </p>
      </div>
      <button
        v-if="canManage"
        type="button"
        class="rounded-lg bg-amber-500 hover:bg-amber-600 text-slate-950 font-semibold px-4 py-2 text-sm shadow-sm"
        @click="openCreateModal"
      >
        + New Store
      </button>
    </div>

    <!-- Filters Bar -->
    <div class="bg-white rounded-xl border border-slate-200 p-4 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3">
      <div class="lg:col-span-2">
        <label class="block text-xs font-medium text-slate-600 mb-1">Search</label>
        <input
          v-model="filters.search"
          type="text"
          placeholder="Search store code, name, location..."
          class="w-full rounded-lg border border-slate-300 px-3 py-1.5 text-sm"
          @keyup.enter="loadStores(1)"
        />
      </div>

      <div>
        <label class="block text-xs font-medium text-slate-600 mb-1">Status</label>
        <select
          v-model="filters.is_active"
          class="w-full rounded-lg border border-slate-300 px-3 py-1.5 text-sm bg-white"
          @change="loadStores(1)"
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
          @change="loadStores(1)"
        >
          <option value="code">Store Code</option>
          <option value="name">Store Name</option>
          <option value="location">Location</option>
          <option value="created_at">Created Date</option>
        </select>
      </div>

      <div class="flex items-end gap-2">
        <div class="flex-1">
          <label class="block text-xs font-medium text-slate-600 mb-1">Order</label>
          <select
            v-model="filters.sort_dir"
            class="w-full rounded-lg border border-slate-300 px-3 py-1.5 text-sm bg-white"
            @change="loadStores(1)"
          >
            <option value="asc">Asc</option>
            <option value="desc">Desc</option>
          </select>
        </div>
        <button
          type="button"
          class="rounded-lg bg-slate-900 hover:bg-slate-800 text-white px-3.5 py-1.5 text-sm font-medium"
          @click="loadStores(1)"
        >
          Filter
        </button>
      </div>
    </div>

    <div
      v-if="loadError"
      class="rounded-lg bg-red-50 border border-red-200 p-4 text-sm text-red-700"
    >
      {{ loadError }}
    </div>

    <!-- Data Table -->
    <div class="bg-white rounded-xl border border-slate-200 overflow-hidden shadow-sm">
      <div v-if="loading" class="p-8 text-center text-sm text-slate-500">
        Loading stores...
      </div>

      <div v-else-if="stores.length === 0" class="p-8 text-center text-sm text-slate-500">
        No stores found matching the current filters.
      </div>

      <div v-else class="overflow-x-auto">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
          <thead class="bg-slate-50 text-xs font-semibold uppercase text-slate-600">
            <tr>
              <th class="px-4 py-3 text-left">Store Code</th>
              <th class="px-4 py-3 text-left">Store Name</th>
              <th class="px-4 py-3 text-left">Location</th>
              <th class="px-4 py-3 text-left">Description</th>
              <th class="px-4 py-3 text-left">Status</th>
              <th v-if="canManage" class="px-4 py-3 text-right">Actions</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-200">
            <tr v-for="st in stores" :key="st.id" class="hover:bg-slate-50">
              <td class="px-4 py-3 font-mono text-xs font-semibold text-slate-900">
                {{ st.code }}
              </td>
              <td class="px-4 py-3 font-medium text-slate-900">
                {{ st.name }}
              </td>
              <td class="px-4 py-3 text-xs text-slate-700">
                {{ st.location || '—' }}
              </td>
              <td class="px-4 py-3 text-xs text-slate-500 max-w-xs truncate">
                {{ st.description || '—' }}
              </td>
              <td class="px-4 py-3">
                <StatusBadge :active="Boolean(st.is_active)" />
              </td>
              <td v-if="canManage" class="px-4 py-3 text-right space-x-2">
                <button
                  type="button"
                  class="text-xs font-semibold text-blue-600 hover:text-blue-800"
                  @click="openEditModal(st)"
                >
                  Edit
                </button>
                <button
                  v-if="st.is_active"
                  type="button"
                  :disabled="busyId === st.id"
                  class="text-xs font-semibold text-amber-600 hover:text-amber-800 disabled:opacity-50"
                  @click="handleDeactivate(st)"
                >
                  Deactivate
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <PaginationBar :meta="paginationMeta" @change="loadStores" />
    </div>

    <!-- Create / Edit Modal -->
    <div
      v-if="showModal"
      class="fixed inset-0 z-40 flex items-center justify-center bg-slate-950/60 p-4"
    >
      <div class="w-full max-w-lg rounded-xl bg-white shadow-xl border border-slate-200 overflow-hidden">
        <div class="px-6 py-4 bg-slate-900 text-white flex items-center justify-between">
          <h3 class="text-base font-bold">
            {{ editingId ? 'Edit Store / Warehouse' : 'Create Store / Warehouse' }}
          </h3>
          <button type="button" class="text-slate-400 hover:text-white" @click="closeModal">
            ✕
          </button>
        </div>

        <form class="p-6 space-y-4" @submit.prevent="saveStore">
          <div v-if="formError" class="rounded-lg bg-red-50 border border-red-200 p-3 text-xs text-red-700">
            {{ formError }}
          </div>

          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label class="block text-xs font-semibold text-slate-700 mb-1">Store Code *</label>
              <input
                v-model="form.code"
                type="text"
                required
                class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm uppercase"
                placeholder="STR-KIT"
              />
              <p v-if="fieldErrors.code" class="mt-1 text-xs text-red-600">
                {{ fieldErrors.code[0] }}
              </p>
            </div>

            <div>
              <label class="block text-xs font-semibold text-slate-700 mb-1">Location</label>
              <input
                v-model="form.location"
                type="text"
                class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                placeholder="Basement B1"
              />
              <p v-if="fieldErrors.location" class="mt-1 text-xs text-red-600">
                {{ fieldErrors.location[0] }}
              </p>
            </div>
          </div>

          <div>
            <label class="block text-xs font-semibold text-slate-700 mb-1">Store Name *</label>
            <input
              v-model="form.name"
              type="text"
              required
              class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
              placeholder="Central Dry & Cold Store"
            />
            <p v-if="fieldErrors.name" class="mt-1 text-xs text-red-600">
              {{ fieldErrors.name[0] }}
            </p>
          </div>

          <div>
            <label class="block text-xs font-semibold text-slate-700 mb-1">Description</label>
            <textarea
              v-model="form.description"
              rows="2"
              class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
              placeholder="Store responsibilities and storage notes"
            ></textarea>
            <p v-if="fieldErrors.description" class="mt-1 text-xs text-red-600">
              {{ fieldErrors.description[0] }}
            </p>
          </div>

          <div class="flex items-center gap-2">
            <input
              id="store_active"
              v-model="form.is_active"
              type="checkbox"
              class="rounded border-slate-300 text-amber-500"
            />
            <label for="store_active" class="text-xs font-medium text-slate-700">
              Active Store (available for GRNs and Bulk Stock Issues)
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
              {{ submitting ? 'Saving...' : 'Save Store' }}
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

const canManage = computed(() => hasRole(['admin', 'store_manager']))

const stores = ref([])
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
  location: '',
  description: '',
  is_active: true
})

const loadStores = async (page = 1) => {
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

    const res = await apiFetch('/stores', { query })
    stores.value = res.data || []
    paginationMeta.value = {
      current_page: res.current_page || 1,
      last_page: res.last_page || 1,
      total: res.total || 0,
      from: res.from || 0,
      to: res.to || 0
    }
  } catch (err) {
    const parsed = extractApiError(err, 'Failed to load stores.')
    loadError.value = parsed.message
  } finally {
    loading.value = false
  }
}

const openCreateModal = () => {
  editingId.value = null
  form.code = ''
  form.name = ''
  form.location = ''
  form.description = ''
  form.is_active = true
  formError.value = ''
  fieldErrors.value = {}
  showModal.value = true
}

const openEditModal = (st) => {
  editingId.value = st.id
  form.code = st.code
  form.name = st.name
  form.location = st.location || ''
  form.description = st.description || ''
  form.is_active = Boolean(st.is_active)
  formError.value = ''
  fieldErrors.value = {}
  showModal.value = true
}

const closeModal = () => {
  showModal.value = false
}

const saveStore = async () => {
  if (submitting.value) return
  submitting.value = true
  formError.value = ''
  fieldErrors.value = {}

  try {
    if (editingId.value) {
      await apiFetch(`/stores/${editingId.value}`, {
        method: 'PUT',
        body: { ...form }
      })
      showToast('Store updated successfully.')
    } else {
      await apiFetch('/stores', {
        method: 'POST',
        body: { ...form }
      })
      showToast('Store created successfully.')
    }
    closeModal()
    await loadStores(paginationMeta.value.current_page)
  } catch (err) {
    const parsed = extractApiError(err, 'Unable to save store.')
    formError.value = parsed.message
    fieldErrors.value = parsed.fieldErrors
  } finally {
    submitting.value = false
  }
}

const handleDeactivate = async (st) => {
  if (busyId.value) return
  busyId.value = st.id
  try {
    await apiFetch(`/stores/${st.id}/deactivate`, { method: 'PATCH' })
    showToast(`Store ${st.code} deactivated.`)
    await loadStores(paginationMeta.value.current_page)
  } catch (err) {
    const parsed = extractApiError(err, 'Failed to deactivate store.')
    showToast(parsed.message, 'error')
  } finally {
    busyId.value = null
  }
}

onMounted(() => {
  loadStores(1)
})
</script>
