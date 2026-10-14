<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DepartmentController;
use App\Http\Controllers\Api\GrnController;
use App\Http\Controllers\Api\InventoryController;
use App\Http\Controllers\Api\ItemController;
use App\Http\Controllers\Api\PurchaseOrderController;
use App\Http\Controllers\Api\RequisitionController;
use App\Http\Controllers\Api\StockIssueController;
use App\Http\Controllers\Api\StoreController;
use App\Http\Controllers\Api\SupplierController;
use Illuminate\Support\Facades\Route;

// Public Authentication Route with rate limiting
Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1')->name('auth.login');

// Protected API Routes (Requires valid Sanctum token and active user account)
Route::middleware(['auth:sanctum', 'role'])->group(function () {
    Route::get('/auth/me', [AuthController::class, 'me'])->name('auth.me');
    Route::post('/auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
    Route::get('/user', [AuthController::class, 'me'])->name('user.me');

    // Master Data: Read-only access for all authenticated active users
    Route::get('/suppliers', [SupplierController::class, 'index'])->name('suppliers.index');
    Route::get('/suppliers/{supplier}', [SupplierController::class, 'show'])->name('suppliers.show');

    Route::get('/items', [ItemController::class, 'index'])->name('items.index');
    Route::get('/items/{item}', [ItemController::class, 'show'])->name('items.show');

    Route::get('/stores', [StoreController::class, 'index'])->name('stores.index');
    Route::get('/stores/{store}', [StoreController::class, 'show'])->name('stores.show');

    Route::get('/departments', [DepartmentController::class, 'index'])->name('departments.index');
    Route::get('/departments/{department}', [DepartmentController::class, 'show'])->name('departments.show');

    // Suppliers Management: Admin & Procurement Officer
    Route::middleware('role:admin,procurement_officer')->group(function () {
        Route::post('/suppliers', [SupplierController::class, 'store'])->name('suppliers.store');
        Route::match(['put', 'patch'], '/suppliers/{supplier}', [SupplierController::class, 'update'])->name('suppliers.update');
        Route::patch('/suppliers/{supplier}/deactivate', [SupplierController::class, 'deactivate'])->name('suppliers.deactivate');
        Route::delete('/suppliers/{supplier}', [SupplierController::class, 'destroy'])->name('suppliers.destroy');
    });

    // Items Management: Admin, Procurement Officer & Store Manager
    Route::middleware('role:admin,procurement_officer,store_manager')->group(function () {
        Route::post('/items', [ItemController::class, 'store'])->name('items.store');
        Route::match(['put', 'patch'], '/items/{item}', [ItemController::class, 'update'])->name('items.update');
        Route::patch('/items/{item}/deactivate', [ItemController::class, 'deactivate'])->name('items.deactivate');
        Route::delete('/items/{item}', [ItemController::class, 'destroy'])->name('items.destroy');
    });

    // Stores Management: Admin & Store Manager
    Route::middleware('role:admin,store_manager')->group(function () {
        Route::post('/stores', [StoreController::class, 'store'])->name('stores.store');
        Route::match(['put', 'patch'], '/stores/{store}', [StoreController::class, 'update'])->name('stores.update');
        Route::patch('/stores/{store}/deactivate', [StoreController::class, 'deactivate'])->name('stores.deactivate');
        Route::delete('/stores/{store}', [StoreController::class, 'destroy'])->name('stores.destroy');
    });

    // Departments Management: Admin
    Route::middleware('role:admin')->group(function () {
        Route::post('/departments', [DepartmentController::class, 'store'])->name('departments.store');
        Route::match(['put', 'patch'], '/departments/{department}', [DepartmentController::class, 'update'])->name('departments.update');
        Route::patch('/departments/{department}/deactivate', [DepartmentController::class, 'deactivate'])->name('departments.deactivate');
        Route::delete('/departments/{department}', [DepartmentController::class, 'destroy'])->name('departments.destroy');
    });

    // Part 3: Store Requisitions (SR) & Purchase Requisitions (PR)
    Route::get('/requisitions', [RequisitionController::class, 'index'])->name('requisitions.index');
    Route::get('/requisitions/{requisition}', [RequisitionController::class, 'show'])->name('requisitions.show');

    Route::middleware('role:department_staff,hod,procurement_officer,store_manager,admin')->group(function () {
        Route::post('/requisitions', [RequisitionController::class, 'store'])->name('requisitions.store');
    });

    Route::middleware('role:department_staff,hod,admin')->group(function () {
        Route::match(['put', 'patch'], '/requisitions/{requisition}', [RequisitionController::class, 'update'])->name('requisitions.update');
    });

    Route::middleware('role:hod')->group(function () {
        Route::post('/requisitions/{requisition}/hod-approve', [RequisitionController::class, 'hodApprove'])->name('requisitions.hod-approve');
    });

    Route::middleware('role:gm')->group(function () {
        Route::post('/requisitions/{requisition}/gm-approve', [RequisitionController::class, 'gmApprove'])->name('requisitions.gm-approve');
    });

    Route::middleware('role:hod,gm')->group(function () {
        Route::post('/requisitions/{requisition}/reject', [RequisitionController::class, 'reject'])->name('requisitions.reject');
    });

    // Part 4: Procurement & Purchase Orders (PO)
    Route::middleware('role:procurement_officer,store_manager,store_keeper,gm,hod,admin')->group(function () {
        Route::get('/purchase-orders', [PurchaseOrderController::class, 'index'])->name('purchase-orders.index');
        Route::get('/purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'show'])->name('purchase-orders.show');
    });

    Route::middleware('role:procurement_officer,admin')->group(function () {
        Route::post('/purchase-orders', [PurchaseOrderController::class, 'store'])->name('purchase-orders.store');
        Route::match(['put', 'patch'], '/purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'update'])->name('purchase-orders.update');
        Route::post('/purchase-orders/{purchaseOrder}/send', [PurchaseOrderController::class, 'send'])->name('purchase-orders.send');
        Route::patch('/purchase-orders/{purchaseOrder}/status', [PurchaseOrderController::class, 'updateStatus'])->name('purchase-orders.status');
    });

    // Part 5: Goods Receiving Notes (GRN) & Atomic Stock IN
    Route::middleware('role:store_keeper,store_manager,procurement_officer,gm,admin')->group(function () {
        Route::get('/grns', [GrnController::class, 'index'])->name('grns.index');
        Route::get('/grns/{grn}', [GrnController::class, 'show'])->name('grns.show');
    });

    Route::middleware('role:store_keeper,store_manager,admin')->group(function () {
        Route::post('/grns', [GrnController::class, 'store'])->name('grns.store');
    });

    Route::middleware('role:store_manager')->group(function () {
        Route::post('/grns/{grn}/approve', [GrnController::class, 'approve'])->name('grns.approve');
        Route::post('/grns/{grn}/reject', [GrnController::class, 'reject'])->name('grns.reject');
    });

    // Part 6: Bulk Stock Issues & Atomic Stock OUT
    Route::middleware('role:store_keeper,store_manager,department_staff,hod,procurement_officer,gm,admin')->group(function () {
        Route::get('/stock-issues', [StockIssueController::class, 'index'])->name('stock-issues.index');
        Route::get('/stock-issues/{stockIssue}', [StockIssueController::class, 'show'])->name('stock-issues.show');
    });

    Route::middleware('role:store_keeper,store_manager,admin')->group(function () {
        Route::post('/stock-issues', [StockIssueController::class, 'store'])->name('stock-issues.store');
        Route::post('/stock-issues/{stockIssue}/post', [StockIssueController::class, 'post'])->name('stock-issues.post');
    });

    // Part 7: Stock Ledger, Current Stock, Stock Taking, and Adjustments
    Route::middleware('role:store_keeper,store_manager,department_staff,hod,procurement_officer,gm,admin')->group(function () {
        Route::get('/inventory/current-stock', [InventoryController::class, 'currentStock'])->name('inventory.current-stock');
        Route::get('/inventory/ledger', [InventoryController::class, 'ledger'])->name('inventory.ledger');
        Route::get('/inventory/reconciliation', [InventoryController::class, 'reconciliation'])->name('inventory.reconciliation');
        Route::get('/inventory/stock-takes', [InventoryController::class, 'stockTakes'])->name('inventory.stock-takes.index');
        Route::get('/inventory/stock-takes/{stockTake}', [InventoryController::class, 'showStockTake'])->name('inventory.stock-takes.show');
    });

    Route::middleware('role:store_keeper,store_manager,admin')->group(function () {
        Route::post('/inventory/stock-takes', [InventoryController::class, 'storeStockTake'])->name('inventory.stock-takes.store');
        Route::match(['put', 'patch'], '/inventory/stock-takes/{stockTake}', [InventoryController::class, 'updateStockTake'])->name('inventory.stock-takes.update');
    });

    Route::middleware('role:store_manager,admin')->group(function () {
        Route::post('/inventory/stock-takes/{stockTake}/approve', [InventoryController::class, 'approveStockAdjustment'])->name('inventory.stock-takes.approve');
    });
});
