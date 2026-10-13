<template>
  <div class="space-y-6">
    <div>
      <h2 class="text-xl font-bold text-slate-900">
        Back-Office Purchasing & Inventory Overview
      </h2>
      <p class="text-sm text-slate-600 mt-1">
        Standalone Hotel Warehouse & Procurement Management System (No POS / No Recipe Auto-Deduction)
      </p>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
      <NuxtLink
        to="/suppliers"
        class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm hover:border-amber-400 transition-colors"
      >
        <div class="text-xs font-semibold uppercase tracking-wider text-slate-500">
          Master Data
        </div>
        <div class="text-lg font-bold text-slate-900 mt-1">
          Suppliers
        </div>
        <p class="text-xs text-slate-500 mt-1">
          Manage approved hospitality vendors and contact directory
        </p>
      </NuxtLink>

      <NuxtLink
        to="/items"
        class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm hover:border-amber-400 transition-colors"
      >
        <div class="text-xs font-semibold uppercase tracking-wider text-slate-500">
          Master Data
        </div>
        <div class="text-lg font-bold text-slate-900 mt-1">
          Items / Products
        </div>
        <p class="text-xs text-slate-500 mt-1">
          Manage SKUs, categories, units of measure (UoM), and par levels
        </p>
      </NuxtLink>

      <NuxtLink
        to="/stores"
        class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm hover:border-amber-400 transition-colors"
      >
        <div class="text-xs font-semibold uppercase tracking-wider text-slate-500">
          Master Data
        </div>
        <div class="text-lg font-bold text-slate-900 mt-1">
          Stores / Warehouses
        </div>
        <p class="text-xs text-slate-500 mt-1">
          Manage central stores, cellar, and housekeeping sub-stores
        </p>
      </NuxtLink>

      <NuxtLink
        to="/departments"
        class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm hover:border-amber-400 transition-colors"
      >
        <div class="text-xs font-semibold uppercase tracking-wider text-slate-500">
          Master Data
        </div>
        <div class="text-lg font-bold text-slate-900 mt-1">
          Departments / Cost Centers
        </div>
        <p class="text-xs text-slate-500 mt-1">
          Manage hotel operational departments and cost allocation centers
        </p>
      </NuxtLink>
    </div>
  </div>
</template>
