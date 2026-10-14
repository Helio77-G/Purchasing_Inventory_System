<template>
  <div class="min-h-screen bg-slate-50 flex flex-col md:flex-row text-slate-800">
    <!-- Sidebar Navigation -->
    <aside class="w-full md:w-64 bg-slate-900 text-slate-100 flex-shrink-0 flex flex-col justify-between">
      <div>
        <div class="px-5 py-4 border-b border-slate-800">
          <div class="text-xs uppercase tracking-wider text-amber-400 font-semibold">
            Hotel Back-Office
          </div>
          <h1 class="text-base font-bold text-white mt-0.5">
            Purchasing & Inventory
          </h1>
        </div>

        <nav class="p-3 space-y-1">
          <NuxtLink
            to="/"
            class="flex items-center gap-2.5 px-3 py-2 rounded-md text-sm font-medium transition-colors"
            active-class="bg-amber-500/20 text-amber-300 border border-amber-500/30"
          >
            <span>Dashboard</span>
          </NuxtLink>

          <div class="pt-3 pb-1 px-3 text-[11px] font-semibold uppercase tracking-wider text-slate-400">
            Purchasing & Store Workflows
          </div>

          <NuxtLink
            to="/requisitions"
            class="flex items-center gap-2.5 px-3 py-2 rounded-md text-sm font-medium text-slate-300 hover:bg-slate-800 hover:text-white transition-colors"
            active-class="bg-amber-500/20 text-amber-300 border border-amber-500/30"
          >
            <span>Requisitions (SR / PR)</span>
          </NuxtLink>

          <NuxtLink
            to="/purchase-orders"
            class="flex items-center gap-2.5 px-3 py-2 rounded-md text-sm font-medium text-slate-300 hover:bg-slate-800 hover:text-white transition-colors"
            active-class="bg-amber-500/20 text-amber-300 border border-amber-500/30"
          >
            <span>Purchase Orders (PO)</span>
          </NuxtLink>

          <NuxtLink
            to="/grns"
            class="flex items-center gap-2.5 px-3 py-2 rounded-md text-sm font-medium text-slate-300 hover:bg-slate-800 hover:text-white transition-colors"
            active-class="bg-amber-500/20 text-amber-300 border border-amber-500/30"
          >
            <span>Goods Receiving (GRN)</span>
          </NuxtLink>

          <NuxtLink
            to="/stock-issues"
            class="flex items-center gap-2.5 px-3 py-2 rounded-md text-sm font-medium text-slate-300 hover:bg-slate-800 hover:text-white transition-colors"
            active-class="bg-amber-500/20 text-amber-300 border border-amber-500/30"
          >
            <span>Bulk Stock Issues (OUT)</span>
          </NuxtLink>

          <div class="pt-3 pb-1 px-3 text-[11px] font-semibold uppercase tracking-wider text-slate-400">
            Inventory & Warehouse Audit
          </div>

          <NuxtLink
            to="/inventory/current-stock"
            class="flex items-center gap-2.5 px-3 py-2 rounded-md text-sm font-medium text-slate-300 hover:bg-slate-800 hover:text-white transition-colors"
            active-class="bg-amber-500/20 text-amber-300 border border-amber-500/30"
          >
            <span>Current Stock & Par Levels</span>
          </NuxtLink>

          <NuxtLink
            to="/inventory/ledger"
            class="flex items-center gap-2.5 px-3 py-2 rounded-md text-sm font-medium text-slate-300 hover:bg-slate-800 hover:text-white transition-colors"
            active-class="bg-amber-500/20 text-amber-300 border border-amber-500/30"
          >
            <span>Stock Ledger Audit Trail</span>
          </NuxtLink>

          <NuxtLink
            to="/inventory/stock-takes"
            class="flex items-center gap-2.5 px-3 py-2 rounded-md text-sm font-medium text-slate-300 hover:bg-slate-800 hover:text-white transition-colors"
            active-class="bg-amber-500/20 text-amber-300 border border-amber-500/30"
          >
            <span>Stock Taking & Adjustments</span>
          </NuxtLink>

          <div class="pt-3 pb-1 px-3 text-[11px] font-semibold uppercase tracking-wider text-slate-400">
            Master Data
          </div>

          <NuxtLink
            to="/suppliers"
            class="flex items-center gap-2.5 px-3 py-2 rounded-md text-sm font-medium text-slate-300 hover:bg-slate-800 hover:text-white transition-colors"
            active-class="bg-amber-500/20 text-amber-300 border border-amber-500/30"
          >
            <span>Suppliers</span>
          </NuxtLink>

          <NuxtLink
            to="/items"
            class="flex items-center gap-2.5 px-3 py-2 rounded-md text-sm font-medium text-slate-300 hover:bg-slate-800 hover:text-white transition-colors"
            active-class="bg-amber-500/20 text-amber-300 border border-amber-500/30"
          >
            <span>Items / Products</span>
          </NuxtLink>

          <NuxtLink
            to="/stores"
            class="flex items-center gap-2.5 px-3 py-2 rounded-md text-sm font-medium text-slate-300 hover:bg-slate-800 hover:text-white transition-colors"
            active-class="bg-amber-500/20 text-amber-300 border border-amber-500/30"
          >
            <span>Stores / Warehouses</span>
          </NuxtLink>

          <NuxtLink
            to="/departments"
            class="flex items-center gap-2.5 px-3 py-2 rounded-md text-sm font-medium text-slate-300 hover:bg-slate-800 hover:text-white transition-colors"
            active-class="bg-amber-500/20 text-amber-300 border border-amber-500/30"
          >
            <span>Departments / Cost Centers</span>
          </NuxtLink>
        </nav>
      </div>

      <!-- User Footer -->
      <div class="p-4 border-t border-slate-800 bg-slate-950/50 text-xs">
        <div v-if="user" class="space-y-1.5">
          <div class="font-semibold text-white truncate">{{ user.name }}</div>
          <div class="text-slate-400 truncate">{{ user.email }}</div>
          <div class="flex flex-wrap gap-1 pt-1">
            <span class="px-2 py-0.5 rounded bg-amber-500/20 text-amber-300 font-medium">
              {{ user.role?.name || 'User' }}
            </span>
            <span
              v-if="user.department"
              class="px-2 py-0.5 rounded bg-slate-800 text-slate-300"
            >
              {{ user.department.code }}
            </span>
          </div>
          <button
            type="button"
            class="mt-2 w-full rounded bg-slate-800 hover:bg-red-600/80 text-slate-200 hover:text-white px-3 py-1.5 font-medium transition-colors"
            @click="logout"
          >
            Sign Out
          </button>
        </div>
        <div v-else>
          <NuxtLink
            to="/login"
            class="block text-center rounded bg-amber-500 hover:bg-amber-600 text-slate-950 font-semibold px-3 py-1.5"
          >
            Sign In
          </NuxtLink>
        </div>
      </div>
    </aside>

    <!-- Main Content -->
    <div class="flex-1 flex flex-col min-w-0">
      <header class="bg-white border-b border-slate-200 px-6 py-3.5 flex items-center justify-between">
        <div class="text-sm text-slate-500">
          Standalone Hotel Purchasing & Warehouse Inventory System
        </div>
        <div v-if="user" class="text-xs text-slate-600">
          Signed in as <span class="font-semibold text-slate-900">{{ user.name }}</span>
        </div>
      </header>

      <main class="flex-1 p-6">
        <slot />
      </main>
    </div>

    <AppToast />
  </div>
</template>

<script setup>
const { user, token, fetchUser, logout } = useAuth()

onMounted(async () => {
  if (token.value && !user.value) {
    await fetchUser()
  }
})
</script>
