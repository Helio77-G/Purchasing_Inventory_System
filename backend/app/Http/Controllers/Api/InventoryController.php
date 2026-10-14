<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StockTake;
use App\Services\InventoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class InventoryController extends Controller
{
    public function __construct(
        protected InventoryService $inventoryService
    ) {}

    /**
     * Get paginated Current Stock balances across stores.
     */
    public function currentStock(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'store_id' => ['nullable', 'integer', Rule::exists('stores', 'id')],
            'item_id' => ['nullable', 'integer', Rule::exists('items', 'id')],
            'category' => ['nullable', 'string', 'max:100'],
            'below_par' => ['nullable', 'boolean'],
            'low_stock' => ['nullable', 'boolean'],
            'search' => ['nullable', 'string', 'max:150'],
            'sort_by' => ['nullable', 'string', Rule::in(['id', 'quantity', 'created_at', 'updated_at'])],
            'sort_dir' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $paginator = $this->inventoryService->getCurrentStock($validated);

        return response()->json($paginator);
    }

    /**
     * Get paginated Stock Ledger audit trail movements.
     */
    public function ledger(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'store_id' => ['nullable', 'integer', Rule::exists('stores', 'id')],
            'item_id' => ['nullable', 'integer', Rule::exists('items', 'id')],
            'department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')],
            'transaction_type' => ['nullable', 'string', Rule::in(['IN', 'OUT', 'ADJ'])],
            'reference_type' => ['nullable', 'string', 'max:64'],
            'reference_number' => ['nullable', 'string', 'max:64'],
            'created_by' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'search' => ['nullable', 'string', 'max:150'],
            'sort_by' => ['nullable', 'string', Rule::in(['id', 'transaction_at', 'quantity', 'balance_after'])],
            'sort_dir' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $paginator = $this->inventoryService->getStockLedger($validated);

        return response()->json($paginator);
    }

    /**
     * Reconcile current_stocks snapshots against stock_ledger transaction sum.
     */
    public function reconciliation(Request $request): JsonResponse
    {
        $storeId = $request->filled('store_id') ? (int) $request->input('store_id') : null;
        $itemId = $request->filled('item_id') ? (int) $request->input('item_id') : null;

        $report = $this->inventoryService->reconcileStock($storeId, $itemId);

        return response()->json([
            'data' => $report,
        ]);
    }

    /**
     * List stock-taking sessions.
     */
    public function stockTakes(Request $request): JsonResponse
    {
        $query = StockTake::query()
            ->with(['store', 'creator', 'counter', 'approver'])
            ->withCount('items');

        if ($request->filled('store_id')) {
            $query->where('store_id', (int) $request->input('store_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', (string) $request->input('status'));
        }

        if ($request->filled('search')) {
            $search = trim((string) $request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('stock_take_number', 'like', "%{$search}%")
                    ->orWhere('reason', 'like', "%{$search}%")
                    ->orWhere('notes', 'like', "%{$search}%");
            });
        }

        $perPage = min(max((int) $request->input('per_page', 15), 1), 100);
        $paginator = $query->orderByDesc('id')->paginate($perPage);

        return response()->json($paginator);
    }

    /**
     * Initiate a new stock-taking session with a system quantity snapshot.
     */
    public function storeStockTake(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'store_id' => ['required', 'integer', Rule::exists('stores', 'id')],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['nullable', 'array'],
            'items.*.item_id' => ['required', 'integer', Rule::exists('items', 'id')],
            'items.*.physical_quantity' => ['nullable', 'numeric', 'min:0'],
            'items.*.notes' => ['nullable', 'string', 'max:500'],
        ]);

        $stockTake = $this->inventoryService->createStockTake($validated, $request->user());

        return response()->json([
            'message' => 'Stock-taking session initiated successfully.',
            'data' => $stockTake,
        ], 201);
    }

    /**
     * Display a single stock take session with item lines and variances.
     */
    public function showStockTake(StockTake $stockTake): JsonResponse
    {
        $stockTake->load([
            'store',
            'creator',
            'counter',
            'approver',
            'items.item',
            'stockLedgerEntries.actor',
        ]);

        return response()->json([
            'data' => $stockTake,
        ]);
    }

    /**
     * Update physical counts for an eligible Draft stock take session.
     */
    public function updateStockTake(Request $request, StockTake $stockTake): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'min:3', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['nullable', 'array'],
            'items.*.item_id' => ['required', 'integer', Rule::exists('items', 'id')],
            'items.*.physical_quantity' => ['required', 'numeric', 'min:0'],
            'items.*.notes' => ['nullable', 'string', 'max:500'],
        ]);

        $updated = $this->inventoryService->updateStockTake($stockTake, $validated, $request->user());

        return response()->json([
            'message' => 'Stock-taking counts updated successfully.',
            'data' => $updated,
        ]);
    }

    /**
     * Approve and post stock adjustment from a stock take session.
     */
    public function approveStockAdjustment(Request $request, StockTake $stockTake): JsonResponse
    {
        $forceReconcile = (bool) $request->input('force_reconcile', false);
        $approved = $this->inventoryService->approveStockAdjustment($stockTake, $request->user(), $forceReconcile);

        return response()->json([
            'message' => 'Stock adjustment approved and posted successfully. Stock Ledger ADJ movements recorded.',
            'data' => $approved,
        ]);
    }
}
