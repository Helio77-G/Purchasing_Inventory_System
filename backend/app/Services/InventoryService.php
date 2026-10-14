<?php

namespace App\Services;

use App\Models\CurrentStock;
use App\Models\Item;
use App\Models\Role;
use App\Models\StockLedger;
use App\Models\StockTake;
use App\Models\StockTakeItem;
use App\Models\Store;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class InventoryService
{
    /**
     * Get paginated Current Stock balances across stores with item details,
     * low-par-level indicators, and stock valuation.
     */
    public function getCurrentStock(array $filters): LengthAwarePaginator
    {
        $query = CurrentStock::with(['item', 'store']);

        if (! empty($filters['store_id'])) {
            $query->where('store_id', (int) $filters['store_id']);
        }

        if (! empty($filters['item_id'])) {
            $query->where('item_id', (int) $filters['item_id']);
        }

        if (! empty($filters['category'])) {
            $category = trim((string) $filters['category']);
            $query->whereHas('item', function ($q) use ($category) {
                $q->where('category', $category);
            });
        }

        if (! empty($filters['search'])) {
            $search = trim((string) $filters['search']);
            $query->where(function ($q) use ($search) {
                $q->whereHas('item', function ($iq) use ($search) {
                    $iq->where('sku', 'like', "%{$search}%")
                        ->orWhere('name', 'like', "%{$search}%")
                        ->orWhere('category', 'like', "%{$search}%");
                })->orWhereHas('store', function ($sq) use ($search) {
                    $sq->where('code', 'like', "%{$search}%")
                        ->orWhere('name', 'like', "%{$search}%");
                });
            });
        }

        // Low stock filter: quantity < items.min_par_level
        if (! empty($filters['below_par']) || ! empty($filters['low_stock'])) {
            $query->whereHas('item', function ($iq) {
                $iq->whereColumn('current_stocks.quantity', '<', 'items.min_par_level');
            });
        }

        $sortBy = $filters['sort_by'] ?? 'id';
        $sortDir = strtolower($filters['sort_dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc';

        if (in_array($sortBy, ['id', 'quantity', 'created_at', 'updated_at'], true)) {
            $query->orderBy($sortBy, $sortDir);
        } else {
            $query->orderBy('id', 'asc');
        }

        $perPage = min(max((int) ($filters['per_page'] ?? 15), 1), 100);

        return $query->paginate($perPage);
    }

    /**
     * Get paginated Stock Ledger audit trail movements with filters.
     */
    public function getStockLedger(array $filters): LengthAwarePaginator
    {
        $query = StockLedger::with(['item', 'store', 'department', 'actor']);

        if (! empty($filters['store_id'])) {
            $query->where('store_id', (int) $filters['store_id']);
        }

        if (! empty($filters['item_id'])) {
            $query->where('item_id', (int) $filters['item_id']);
        }

        if (! empty($filters['department_id'])) {
            $query->where('department_id', (int) $filters['department_id']);
        }

        if (! empty($filters['transaction_type'])) {
            $query->where('transaction_type', strtoupper(trim((string) $filters['transaction_type'])));
        }

        if (! empty($filters['reference_type'])) {
            $query->where('reference_type', strtoupper(trim((string) $filters['reference_type'])));
        }

        if (! empty($filters['reference_number'])) {
            $query->where('reference_number', 'like', '%'.trim((string) $filters['reference_number']).'%');
        }

        if (! empty($filters['created_by'])) {
            $query->where('created_by', (int) $filters['created_by']);
        }

        if (! empty($filters['date_from'])) {
            $query->where('transaction_at', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->where('transaction_at', '<=', $filters['date_to'].' 23:59:59');
        }

        if (! empty($filters['search'])) {
            $search = trim((string) $filters['search']);
            $query->where(function ($q) use ($search) {
                $q->where('reference_number', 'like', "%{$search}%")
                    ->orWhere('notes', 'like', "%{$search}%")
                    ->orWhereHas('item', function ($iq) use ($search) {
                        $iq->where('sku', 'like', "%{$search}%")
                            ->orWhere('name', 'like', "%{$search}%");
                    });
            });
        }

        $sortBy = $filters['sort_by'] ?? 'transaction_at';
        $sortDir = strtolower($filters['sort_dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        if (in_array($sortBy, ['id', 'transaction_at', 'quantity', 'balance_after'], true)) {
            $query->orderBy($sortBy, $sortDir);
        } else {
            $query->orderBy('transaction_at', 'desc')->orderBy('id', 'desc');
        }

        $perPage = min(max((int) ($filters['per_page'] ?? 15), 1), 100);

        return $query->paginate($perPage);
    }

    /**
     * Reconcile current_stocks snapshots against authoritative stock_ledger transaction sum.
     */
    public function reconcileStock(?int $storeId = null, ?int $itemId = null): array
    {
        $currentQuery = CurrentStock::with(['item', 'store']);
        if ($storeId) {
            $currentQuery->where('store_id', $storeId);
        }
        if ($itemId) {
            $currentQuery->where('item_id', $itemId);
        }

        $currentStocks = $currentQuery->get();

        $discrepancies = [];
        $totalChecked = 0;

        foreach ($currentStocks as $cs) {
            $totalChecked++;
            $currentQty = round((float) $cs->quantity, 3);

            $ledgerSum = round((float) StockLedger::where('item_id', $cs->item_id)
                ->where('store_id', $cs->store_id)
                ->sum('quantity'), 3);

            if (abs($currentQty - $ledgerSum) > 0.0001) {
                $discrepancies[] = [
                    'item_id' => $cs->item_id,
                    'sku' => $cs->item->sku,
                    'item_name' => $cs->item->name,
                    'store_id' => $cs->store_id,
                    'store_code' => $cs->store->code,
                    'current_stock_quantity' => number_format($currentQty, 3, '.', ''),
                    'ledger_sum_quantity' => number_format($ledgerSum, 3, '.', ''),
                    'variance' => number_format($currentQty - $ledgerSum, 3, '.', ''),
                ];
            }
        }

        return [
            'reconciled' => count($discrepancies) === 0,
            'total_checked' => $totalChecked,
            'discrepancies_count' => count($discrepancies),
            'discrepancies' => $discrepancies,
        ];
    }

    /**
     * Create a new physical stock-taking session with a system quantity snapshot.
     */
    public function createStockTake(array $payload, User $actor): StockTake
    {
        if (! $actor->hasRole([Role::SLUG_STORE_KEEPER, Role::SLUG_STORE_MANAGER, Role::SLUG_ADMIN])) {
            throw new AccessDeniedHttpException('Only Store Keepers, Store Managers, or Administrators can initiate stock takes.');
        }

        $storeId = (int) $payload['store_id'];
        if (! $actor->hasRole(Role::SLUG_ADMIN) && $actor->store_id !== null && (int) $actor->store_id !== $storeId) {
            throw new AccessDeniedHttpException('You can only perform stock takes for your assigned store.');
        }

        /** @var Store $store */
        $store = Store::findOrFail($storeId);
        if (! $store->is_active) {
            throw ValidationException::withMessages([
                'store_id' => ['Cannot perform stock take on an inactive store.'],
            ]);
        }

        return DB::transaction(function () use ($payload, $actor, $store) {
            $stockTake = StockTake::create([
                'stock_take_number' => $this->generateNextStockTakeNumber(),
                'store_id' => $store->id,
                'status' => StockTake::STATUS_DRAFT,
                'created_by' => $actor->id,
                'counted_by' => $actor->id,
                'counted_at' => now(),
                'approved_by' => null,
                'approved_at' => null,
                'reason' => trim((string) $payload['reason']),
                'notes' => $payload['notes'] ?? null,
            ]);

            // Determine items to count (either provided item list or all active items)
            if (! empty($payload['items'])) {
                $itemInputs = collect($payload['items'])->keyBy('item_id');
                $items = Item::whereIn('id', $itemInputs->keys())->where('is_active', true)->get();
            } else {
                $itemInputs = collect();
                $items = Item::where('is_active', true)->get();
            }

            if ($items->isEmpty()) {
                throw ValidationException::withMessages([
                    'items' => ['No active catalog items available for stock take.'],
                ]);
            }

            // Snapshot system quantities
            $currentStockMap = CurrentStock::where('store_id', $store->id)
                ->whereIn('item_id', $items->pluck('id'))
                ->pluck('quantity', 'item_id');

            foreach ($items as $item) {
                $systemQty = round((float) ($currentStockMap[$item->id] ?? 0.0), 3);
                $input = $itemInputs->get($item->id);

                $physicalQty = isset($input['physical_quantity'])
                    ? round((float) $input['physical_quantity'], 3)
                    : $systemQty;

                if ($physicalQty < 0) {
                    throw ValidationException::withMessages([
                        'items' => ["Physical count for item [{$item->sku}] cannot be negative."],
                    ]);
                }

                $varianceQty = round($physicalQty - $systemQty, 3);
                $unitCost = round((float) ($item->unit_cost ?? 0.0), 4);
                $varianceCost = round($varianceQty * $unitCost, 4);

                StockTakeItem::create([
                    'stock_take_id' => $stockTake->id,
                    'item_id' => $item->id,
                    'system_quantity' => $systemQty,
                    'physical_quantity' => $physicalQty,
                    'variance_quantity' => $varianceQty,
                    'unit_cost' => $unitCost,
                    'variance_cost' => $varianceCost,
                    'notes' => $input['notes'] ?? null,
                ]);
            }

            return $stockTake->load(['store', 'creator', 'counter', 'items.item']);
        });
    }

    /**
     * Update physical counts for an eligible Draft stock take session.
     */
    public function updateStockTake(StockTake $stockTake, array $payload, User $actor): StockTake
    {
        if ($stockTake->status !== StockTake::STATUS_DRAFT) {
            throw ValidationException::withMessages([
                'status' => ["Only Draft stock take sessions can be updated. Current status: {$stockTake->status}."],
            ]);
        }

        if (! $actor->hasRole(Role::SLUG_ADMIN) && $actor->store_id !== null && (int) $actor->store_id !== (int) $stockTake->store_id) {
            throw new AccessDeniedHttpException('You can only update stock takes for your assigned store.');
        }

        return DB::transaction(function () use ($stockTake, $payload, $actor) {
            if (isset($payload['reason'])) {
                $stockTake->reason = trim((string) $payload['reason']);
            }
            if (isset($payload['notes'])) {
                $stockTake->notes = $payload['notes'];
            }
            $stockTake->counted_by = $actor->id;
            $stockTake->counted_at = now();
            $stockTake->save();

            if (! empty($payload['items'])) {
                $lines = $stockTake->items->keyBy('item_id');

                foreach ($payload['items'] as $itemInput) {
                    $itemId = (int) ($itemInput['item_id'] ?? 0);
                    /** @var StockTakeItem|null $line */
                    $line = $lines->get($itemId);

                    if ($line && isset($itemInput['physical_quantity'])) {
                        $physicalQty = round((float) $itemInput['physical_quantity'], 3);
                        if ($physicalQty < 0) {
                            throw ValidationException::withMessages([
                                'items' => ["Physical count for item [{$line->item->sku}] cannot be negative."],
                            ]);
                        }

                        $varianceQty = round($physicalQty - (float) $line->system_quantity, 3);
                        $varianceCost = round($varianceQty * (float) $line->unit_cost, 4);

                        $line->update([
                            'physical_quantity' => $physicalQty,
                            'variance_quantity' => $varianceQty,
                            'variance_cost' => $varianceCost,
                            'notes' => $itemInput['notes'] ?? $line->notes,
                        ]);
                    }
                }
            }

            return $stockTake->fresh(['store', 'creator', 'counter', 'items.item']);
        });
    }

    /**
     * Approve and post stock adjustment from a stock take session:
     * - Locks StockTake, CurrentStock, and Item records.
     * - Checks for stale snapshots (rejects if system stock balance changed since count time).
     * - Updates current_stocks by signed variance delta.
     * - Creates StockLedger ADJ entries for items with non-zero variance.
     * - Marks StockTake as Approved with actor and timestamp.
     */
    public function approveStockAdjustment(StockTake $stockTake, User $actor, bool $forceReconcile = false): StockTake
    {
        if (! $actor->hasRole([Role::SLUG_STORE_MANAGER, Role::SLUG_ADMIN])) {
            throw new AccessDeniedHttpException('Only Store Managers or Administrators can approve stock adjustments.');
        }

        if (! $actor->hasRole(Role::SLUG_ADMIN) && $actor->store_id !== null && (int) $actor->store_id !== (int) $stockTake->store_id) {
            throw new AccessDeniedHttpException('You can only approve stock adjustments for your assigned store.');
        }

        return DB::transaction(function () use ($stockTake, $actor, $forceReconcile) {
            /** @var StockTake $lockedTake */
            $lockedTake = StockTake::with(['items.item', 'store'])
                ->lockForUpdate()
                ->findOrFail($stockTake->id);

            if ($lockedTake->status !== StockTake::STATUS_DRAFT) {
                throw ValidationException::withMessages([
                    'status' => ["Only Draft stock takes can be approved. Current status: {$lockedTake->status}."],
                ]);
            }

            foreach ($lockedTake->items as $line) {
                /** @var CurrentStock|null $currentStock */
                $currentStock = CurrentStock::where('item_id', $line->item_id)
                    ->where('store_id', $lockedTake->store_id)
                    ->lockForUpdate()
                    ->first();

                $liveStock = $currentStock ? round((float) $currentStock->quantity, 3) : 0.0;
                $snapshotStock = round((float) $line->system_quantity, 3);
                $physicalQty = round((float) $line->physical_quantity, 3);

                if ($physicalQty < 0) {
                    throw ValidationException::withMessages([
                        'items' => ["Physical count for item [{$line->item->sku}] cannot be negative."],
                    ]);
                }

                // Stale count snapshot check: if live system balance changed after count snapshot
                if (abs($liveStock - $snapshotStock) > 0.0001 && ! $forceReconcile) {
                    throw ValidationException::withMessages([
                        'items' => [
                            "Stale count snapshot detected for item [{$line->item->sku}]. System balance changed from {$snapshotStock} to {$liveStock} since count was taken. Recount or reconcile before posting.",
                        ],
                    ]);
                }

                $variance = round($physicalQty - $liveStock, 3);

                // If non-zero variance, adjust stock balance and record ADJ movement in StockLedger
                if (abs($variance) > 0.0001) {
                    $newBalance = round($liveStock + $variance, 3); // equals physicalQty

                    if ($newBalance < 0) {
                        throw ValidationException::withMessages([
                            'items' => ["Adjustment cannot result in negative stock balance for item [{$line->item->sku}]."],
                        ]);
                    }

                    if ($currentStock) {
                        $currentStock->update(['quantity' => $newBalance]);
                    } else {
                        $currentStock = CurrentStock::create([
                            'item_id' => $line->item_id,
                            'store_id' => $lockedTake->store_id,
                            'quantity' => $newBalance,
                        ]);
                    }

                    // Refresh catalog unit cost
                    /** @var Item $catalogItem */
                    $catalogItem = Item::lockForUpdate()->findOrFail($line->item_id);
                    $unitCost = round((float) ($catalogItem->unit_cost ?? $line->unit_cost), 4);
                    $varianceCost = round($variance * $unitCost, 4);

                    $line->update([
                        'variance_quantity' => $variance,
                        'unit_cost' => $unitCost,
                        'variance_cost' => $varianceCost,
                    ]);

                    StockLedger::create([
                        'item_id' => $line->item_id,
                        'store_id' => $lockedTake->store_id,
                        'department_id' => null,
                        'transaction_type' => StockLedger::TYPE_ADJ,
                        'quantity' => $variance, // signed delta (+ for surplus, - for deficit)
                        'balance_after' => $newBalance,
                        'reference_type' => StockLedger::REF_STOCK_ADJUSTMENT,
                        'reference_id' => $lockedTake->id,
                        'reference_number' => $lockedTake->stock_take_number,
                        'created_by' => $actor->id,
                        'transaction_at' => now(),
                        'notes' => $line->notes ?? $lockedTake->reason,
                        'metadata' => [
                            'stock_take_item_id' => $line->id,
                            'system_quantity' => number_format($liveStock, 3, '.', ''),
                            'physical_quantity' => number_format($physicalQty, 3, '.', ''),
                            'variance_quantity' => number_format($variance, 3, '.', ''),
                            'unit_cost' => number_format($unitCost, 4, '.', ''),
                            'variance_cost' => number_format($varianceCost, 4, '.', ''),
                            'reason' => $lockedTake->reason,
                        ],
                    ]);
                }
            }

            $lockedTake->update([
                'status' => StockTake::STATUS_APPROVED,
                'approved_by' => $actor->id,
                'approved_at' => now(),
            ]);

            return $lockedTake->fresh([
                'store',
                'creator',
                'counter',
                'approver',
                'items.item',
                'stockLedgerEntries.actor',
            ]);
        });
    }

    /**
     * Generate sequential Stock Take number (e.g. ST-202610-0001).
     */
    protected function generateNextStockTakeNumber(): string
    {
        $yearMonth = now()->format('Ym');
        $prefix = "ST-{$yearMonth}-";

        $latest = StockTake::where('stock_take_number', 'like', "{$prefix}%")
            ->orderByDesc('id')
            ->value('stock_take_number');

        $nextSeq = 1;
        if ($latest && preg_match('/(\d{4})$/', $latest, $matches)) {
            $nextSeq = ((int) $matches[1]) + 1;
        }

        return sprintf('%s%04d', $prefix, $nextSeq);
    }
}
