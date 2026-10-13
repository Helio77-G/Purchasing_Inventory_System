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
        to="/requisitions"
        class="rounded-xl border border-amber-300 bg-amber-50/40 p-5 shadow-sm hover:border-amber-500 transition-colors"
      >
        <div class="text-xs font-semibold uppercase tracking-wider text-amber-700">
          Workflows
        </div>
        <div class="text-lg font-bold text-slate-900 mt-1">
          Requisitions (SR & PR)
        </div>
        <p class="text-xs text-slate-600 mt-1">
          Submit Store Requisitions (SR) & Purchase Requisitions (PR) and manage HOD / GM approvals
        </p>
      </NuxtLink>

      <NuxtLink
        to="/purchase-orders"
        class="rounded-xl border border-amber-300 bg-amber-50/40 p-5 shadow-sm hover:border-amber-500 transition-colors"
      >
        <div class="text-xs font-semibold uppercase tracking-wider text-amber-700">
          Workflows
        </div>
        <div class="text-lg font-bold text-slate-900 mt-1">
          Purchase Orders (PO)
        </div>
        <p class="text-xs text-slate-600 mt-1">
          Convert GM-approved PRs into Supplier POs, manage pricing, and track ordered vs received quantities
        </p>
      </NuxtLink>

      <NuxtLink
        to="/grns"
        class="rounded-xl border border-emerald-300 bg-emerald-50/40 p-5 shadow-sm hover:border-emerald-500 transition-colors"
      >
        <div class="text-xs font-semibold uppercase tracking-wider text-emerald-700">
          Stock IN
        </div>
        <div class="text-lg font-bold text-slate-900 mt-1">
          Goods Receiving (GRN)
        </div>
        <p class="text-xs text-slate-600 mt-1">
          Receive supplier deliveries against Sent POs and post atomic Stock IN on Store Manager approval
        </p>
      </NuxtLink>

      <NuxtLink
        to="/stock-issues"
        class="rounded-xl border border-rose-300 bg-rose-50/40 p-5 shadow-sm hover:border-rose-500 transition-colors"
      >
        <div class="text-xs font-semibold uppercase tracking-wider text-rose-700">
          Stock OUT
        </div>
        <div class="text-lg font-bold text-slate-900 mt-1">
          Bulk Stock Issues
        </div>
        <p class="text-xs text-slate-600 mt-1">
          Issue stock from warehouse stores to departments/cost centers against HOD-approved SRs
        </p>
      </NuxtLink>

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
