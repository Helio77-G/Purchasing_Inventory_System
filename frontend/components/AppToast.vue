<template>
  <div class="fixed bottom-4 right-4 z-50 flex flex-col gap-2 max-w-md w-full pointer-events-none px-4">
    <div
      v-for="t in toasts"
      :key="t.id"
      class="pointer-events-auto flex items-start justify-between gap-3 rounded-lg border px-4 py-3 shadow-lg text-sm transition-all"
      :class="
        t.type === 'error'
          ? 'bg-red-50 border-red-200 text-red-800'
          : 'bg-emerald-50 border-emerald-200 text-emerald-800'
      "
      role="alert"
    >
      <div class="font-medium">{{ t.message }}</div>
      <button
        type="button"
        class="text-xs font-semibold opacity-70 hover:opacity-100"
        @click="dismissToast(t.id)"
      >
        ✕
      </button>
    </div>
  </div>
</template>

<script setup>
const { toasts, dismissToast } = useToast()
</script>
