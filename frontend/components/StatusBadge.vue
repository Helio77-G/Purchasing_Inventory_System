<template>
  <span
    class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold"
    :class="badgeClass"
  >
    {{ labelText }}
  </span>
</template>

<script setup>
const props = defineProps({
  active: {
    type: Boolean,
    default: null
  },
  status: {
    type: String,
    default: ''
  }
})

const labelText = computed(() => {
  if (props.status) return props.status
  return props.active ? 'Active' : 'Inactive'
})

const badgeClass = computed(() => {
  if (!props.status) {
    return props.active
      ? 'bg-emerald-100 text-emerald-800'
      : 'bg-slate-200 text-slate-700'
  }
  const s = props.status.toLowerCase()
  if (s.includes('pending') || s.includes('draft') || s.includes('sent') || s.includes('partial')) {
    return 'bg-amber-100 text-amber-800'
  }
  if (s.includes('approved') || s.includes('fully received') || s.includes('issued') || s.includes('posted')) {
    return 'bg-emerald-100 text-emerald-800'
  }
  if (s.includes('rejected') || s.includes('cancelled')) {
    return 'bg-red-100 text-red-800'
  }
  return 'bg-blue-100 text-blue-800'
})
</script>
