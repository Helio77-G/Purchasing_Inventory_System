<template>
  <div
    v-if="meta && meta.total > 0"
    class="flex flex-col sm:flex-row items-center justify-between gap-3 border-t border-slate-200 bg-white px-4 py-3 text-sm text-slate-600"
  >
    <div>
      Showing <span class="font-semibold text-slate-900">{{ meta.from || 0 }}</span> to
      <span class="font-semibold text-slate-900">{{ meta.to || 0 }}</span> of
      <span class="font-semibold text-slate-900">{{ meta.total || 0 }}</span> records
    </div>
    <div class="flex items-center gap-2">
      <button
        type="button"
        class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50"
        :disabled="meta.current_page <= 1"
        @click="$emit('change', meta.current_page - 1)"
      >
        Previous
      </button>
      <span class="text-xs font-medium text-slate-700">
        Page {{ meta.current_page }} of {{ meta.last_page }}
      </span>
      <button
        type="button"
        class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50"
        :disabled="meta.current_page >= meta.last_page"
        @click="$emit('change', meta.current_page + 1)"
      >
        Next
      </button>
    </div>
  </div>
</template>

<script setup>
defineProps({
  meta: {
    type: Object,
    default: () => ({
      current_page: 1,
      last_page: 1,
      total: 0,
      from: 0,
      to: 0
    })
  }
})

defineEmits(['change'])
</script>
