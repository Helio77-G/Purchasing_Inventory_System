<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DepartmentController;
use App\Http\Controllers\Api\ItemController;
use App\Http\Controllers\Api\StoreController;
use App\Http\Controllers\Api\SupplierController;
use Illuminate\Support\Facades\Route;

// Public Authentication Route
Route::post('/auth/login', [AuthController::class, 'login'])->name('auth.login');

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
});
