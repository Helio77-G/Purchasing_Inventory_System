<template>
  <div class="space-y-5">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
      <div>
        <h2 class="text-xl font-bold text-slate-900">Items / Products Catalog</h2>
        <p class="text-xs text-slate-500 mt-0.5">
          Manage hotel SKUs, categories, units of measure (UoM), and minimum par levels
        </p>
      </div>
      <button
        v-if="canManage"
        type="button"
        class="rounded-lg bg-amber-500 hover:bg-amber-600 text-slate-950 font-semibold px-4 py-2 text-sm shadow-sm"
        @click="openCreateModal"
      >
        + New Item
      </button>
    </div>

    <!-- Filters Bar -->
    <div class="bg-white rounded-xl border border-slate-200 p-4 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-6 gap-3">
      <div class="lg:col-span-2">
        <label class="block text-xs font-medium text-slate-600 mb-1">Search</label>
        <input
          v-model="filters.search"
          type="text"
          placeholder="Search SKU, item name, category..."
          class="w-full rounded-lg border border-slate-300 px-3 py-1.5 text-sm"
          @keyup.enter="loadItems(1)"
        />
      </div>

      <div>
        <label class="block text-xs font-medium text-slate-600 mb-1">Category</label>
        <select
          v-model="filters.category"
          class="w-full rounded-lg border border-slate-300 px-3 py-1.5 text-sm bg-white"
          @change="loadItems(1)"
        >
          <option value="">All Categories</option>
          <option v-for="cat in categories" :key="cat" :value="cat">
            {{ cat }}
          </option>
        </select>
      </div>

      <div>
        <label class="block text-xs font-medium text-slate-600 mb-1">Status</label>
        <select
          v-model="filters.is_active"
          class="w-full rounded-lg border border-slate-300 px-3 py-1.5 text-sm bg-white"
          @change="loadItems(1)"
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
          @change="loadItems(1)"
        >
          <option value="sku">SKU</option>
          <option value="name">Item Name</option>
          <option value="category">Category</option>
          <option value="min_par_level">Min Par Level</option>
        </select>
      </div>

      <div class="flex items-end gap-2">
        <div class="flex-1">
          <label class="block text-xs font-medium text-slate-600 mb-1">Order</label>
          <select
            v-model="filters.sort_dir"
            class="w-full rounded-lg border border-slate-300 px-3 py-1.5 text-sm bg-white"
            @change="loadItems(1)"
          >
            <option value="asc">Asc</option>
            <option value="desc">Desc</option>
          </select>
        </div>
        <button
          type="button"
          class="rounded-lg bg-slate-900 hover:bg-slate-800 text-white px-3.5 py-1.5 text-sm font-medium"
          @click="loadItems(1)"
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
        Loading items...
      </div>

      <div v-else-if="items.length === 0" class="p-8 text-center text-sm text-slate-500">
        No items found matching the current filters.
      </div>

      <div v-else class="overflow-x-auto">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
          <thead class="bg-slate-50 text-xs font-semibold uppercase text-slate-600">
            <tr>
              <th class="px-4 py-3 text-left">SKU</th>
              <th class="px-4 py-3 text-left">Item Name</th>
              <th class="px-4 py-3 text-left">Category</th>
              <th class="px-4 py-3 text-left">UoM</th>
              <th class="px-4 py-3 text-right">Min Par Level</th>
              <th class="px-4 py-3 text-left">Status</th>
              <th v-if="canManage" class="px-4 py-3 text-right">Actions</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-200">
            <tr v-for="item in items" :key="item.id" class="hover:bg-slate-50">
              <td class="px-4 py-3 font-mono text-xs font-semibold text-slate-900">
                {{ item.sku }}
              </td>
              <td class="px-4 py-3">
                <div class="font-medium text-slate-900">{{ item.name }}</div>
                <div v-if="item.description" class="text-xs text-slate-500 truncate max-w-xs">
                  {{ item.description }}
                </div>
              </td>
              <td class="px-4 py-3 text-xs text-slate-700">
                {{ item.category }}
              </td>
              <td class="px-4 py-3 font-mono text-xs font-semibold text-slate-700">
                {{ item.uom }}
              </td>
              <td class="px-4 py-3 text-right font-mono text-xs text-slate-900">
                {{ item.min_par_level }}
              </td>
              <td class="px-4 py-3">
                <StatusBadge :active="Boolean(item.is_active)" />
              </td>
              <td v-if="canManage" class="px-4 py-3 text-right space-x-2">
                <button
                  type="button"
                  class="text-xs font-semibold text-blue-600 hover:text-blue-800"
                  @click="openEditModal(item)"
                >
                  Edit
                </button>
                <button
                  v-if="item.is_active"
                  type="button"
                  :disabled="busyId === item.id"
                  class="text-xs font-semibold text-amber-600 hover:text-amber-800 disabled:opacity-50"
                  @click="handleDeactivate(item)"
                >
                  Deactivate
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <PaginationBar :meta="paginationMeta" @change="loadItems" />
    </div>

    <!-- Create / Edit Modal -->
    <div
      v-if="showModal"
      class="fixed inset-0 z-40 flex items-center justify-center bg-slate-950/60 p-4"
    >
      <div class="w-full max-w-lg rounded-xl bg-white shadow-xl border border-slate-200 overflow-hidden">
        <div class="px-6 py-4 bg-slate-900 text-white flex items-center justify-between">
          <h3 class="text-base font-bold">
            {{ editingId ? 'Edit Item / Product' : 'Create Item / Product' }}
          </h3>
          <button type="button" class="text-slate-400 hover:text-white" @click="closeModal">
            ✕
          </button>
        </div>

        <form class="p-6 space-y-4" @submit.prevent="saveItem">
          <div v-if="formError" class="rounded-lg bg-red-50 border border-red-200 p-3 text-xs text-red-700">
            {{ formError }}
          </div>

          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label class="block text-xs font-semibold text-slate-700 mb-1">SKU / Item Code *</label>
              <input
                v-model="form.sku"
                type="text"
                required
                class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm uppercase"
                placeholder="ITM-HK-004"
              />
              <p v-if="fieldErrors.sku" class="mt-1 text-xs text-red-600">
                {{ fieldErrors.sku[0] }}
              </p>
            </div>

            <div>
              <label class="block text-xs font-semibold text-slate-700 mb-1">Category *</label>
              <input
                v-model="form.category"
                type="text"
                list="category-options"
                required
                class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                placeholder="Housekeeping Amenities"
              />
              <datalist id="category-options">
                <option v-for="cat in categories" :key="cat" :value="cat" />
              </datalist>
              <p v-if="fieldErrors.category" class="mt-1 text-xs text-red-600">
                {{ fieldErrors.category[0] }}
              </p>
            </div>
          </div>

          <div>
            <label class="block text-xs font-semibold text-slate-700 mb-1">Item Name *</label>
            <input
              v-model="form.name"
              type="text"
              required
              class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
              placeholder="Luxury Cotton Hand Towel"
            />
            <p v-if="fieldErrors.name" class="mt-1 text-xs text-red-600">
              {{ fieldErrors.name[0] }}
            </p>
          </div>

          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label class="block text-xs font-semibold text-slate-700 mb-1">Unit of Measure (UoM) *</label>
              <select
                v-model="form.uom"
                required
                class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm bg-white"
              >
                <option v-for="u in allowedUoms" :key="u" :value="u">{{ u }}</option>
              </select>
              <p v-if="fieldErrors.uom" class="mt-1 text-xs text-red-600">
                {{ fieldErrors.uom[0] }}
              </p>
            </div>

            <div>
              <label class="block text-xs font-semibold text-slate-700 mb-1">Minimum Par Level *</label>
              <input
                v-model="form.min_par_level"
                type="number"
                step="0.001"
                min="0"
                required
                class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
              />
              <p v-if="fieldErrors.min_par_level" class="mt-1 text-xs text-red-600">
                {{ fieldErrors.min_par_level[0] }}
              </p>
            </div>
          </div>

          <div>
            <label class="block text-xs font-semibold text-slate-700 mb-1">Description</label>
            <textarea
              v-model="form.description"
              rows="2"
              class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
              placeholder="Specification and packaging notes"
            ></textarea>
            <p v-if="fieldErrors.description" class="mt-1 text-xs text-red-600">
              {{ fieldErrors.description[0] }}
            </p>
          </div>

          <div class="flex items-center gap-2">
            <input
              id="item_active"
              v-model="form.is_active"
              type="checkbox"
              class="rounded border-slate-300 text-amber-500"
            />
            <label for="item_active" class="text-xs font-medium text-slate-700">
              Active Item (available for Requisitions and Purchase Orders)
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
              {{ submitting ? 'Saving...' : 'Save Item' }}
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

const canManage = computed(() => hasRole(['admin', 'procurement_officer', 'store_manager']))

const allowedUoms = [
  'PCS', 'BOX', 'KG', 'G', 'L', 'ML', 'BTL', 'PACK', 'BAG', 'TIN', 'CASE', 'ROLL', 'SET', 'DOZ', 'PAIR'
]

const categories = [
  'Housekeeping Linens',
  'Housekeeping Amenities',
  'Cleaning Supplies',
  'Dry Groceries',
  'Beverages',
  'Maintenance Parts'
]

const items = ref([])
const loading = ref(false)
const loadError = ref('')
const busyId = ref(null)

const filters = reactive({
  search: '',
  category: '',
  is_active: '',
  sort_by: 'sku',
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
  sku: '',
  name: '',
  category: 'Housekeeping Amenities',
  uom: 'PCS',
  min_par_level: 10,
  description: '',
  is_active: true
})

const loadItems = async (page = 1) => {
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
    if (filters.category) query.category = filters.category
    if (filters.is_active !== '') query.is_active = filters.is_active

    const res = await apiFetch('/items', { query })
    items.value = res.data || []
    paginationMeta.value = {
      current_page: res.current_page || 1,
      last_page: res.last_page || 1,
      total: res.total || 0,
      from: res.from || 0,
      to: res.to || 0
    }
  } catch (err) {
    const parsed = extractApiError(err, 'Failed to load items.')
    loadError.value = parsed.message
  } finally {
    loading.value = false
  }
}

const openCreateModal = () => {
  editingId.value = null
  form.sku = ''
  form.name = ''
  form.category = 'Housekeeping Amenities'
  form.uom = 'PCS'
  form.min_par_level = 10
  form.description = ''
  form.is_active = true
  formError.value = ''
  fieldErrors.value = {}
  showModal.value = true
}

const openEditModal = (item) => {
  editingId.value = item.id
  form.sku = item.sku
  form.name = item.name
  form.category = item.category
  form.uom = item.uom
  form.min_par_level = item.min_par_level
  form.description = item.description || ''
  form.is_active = Boolean(item.is_active)
  formError.value = ''
  fieldErrors.value = {}
  showModal.value = true
}

const closeModal = () => {
  showModal.value = false
}

const saveItem = async () => {
  if (submitting.value) return
  submitting.value = true
  formError.value = ''
  fieldErrors.value = {}

  try {
    if (editingId.value) {
      await apiFetch(`/items/${editingId.value}`, {
        method: 'PUT',
        body: { ...form }
      })
      showToast('Item updated successfully.')
    } else {
      await apiFetch('/items', {
        method: 'POST',
        body: { ...form }
      })
      showToast('Item created successfully.')
    }
    closeModal()
    await loadItems(paginationMeta.value.current_page)
  } catch (err) {
    const parsed = extractApiError(err, 'Unable to save item.')
    formError.value = parsed.message
    fieldErrors.value = parsed.fieldErrors
  } finally {
    submitting.value = false
  }
}

const handleDeactivate = async (item) => {
  if (busyId.value) return
  busyId.value = item.id
  try {
    await apiFetch(`/items/${item.id}/deactivate`, { method: 'PATCH' })
    showToast(`Item ${item.sku} deactivated.`)
    await loadItems(paginationMeta.value.current_page)
  } catch (err) {
    const parsed = extractApiError(err, 'Failed to deactivate item.')
    showToast(parsed.message, 'error')
  } finally {
    busyId.value = null
  }
}

onMounted(() => {
  loadItems(1)
})
</script>
