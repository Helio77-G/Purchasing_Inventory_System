<?php

namespace App\Services;

use App\Models\CurrentStock;
use App\Models\Department;
use App\Models\Item;
use App\Models\Requisition;
use App\Models\RequisitionItem;
use App\Models\Role;
use App\Models\StockIssue;
use App\Models\StockIssueItem;
use App\Models\StockLedger;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class StockIssueService
{
    /**
     * Create a new Bulk Stock Issue (in Draft status, or immediately posted if post_immediately = true)
     * against an eligible HOD-Approved or Partially-Issued Store Requisition (SR).
     */
    public function createIssue(array $payload, User $actor): StockIssue
    {
        if (! $actor->hasRole([Role::SLUG_STORE_KEEPER, Role::SLUG_STORE_MANAGER, Role::SLUG_ADMIN])) {
            throw new AccessDeniedHttpException('Only Store Keepers, Store Managers, or Administrators can create Stock Issues.');
        }

        $issue = DB::transaction(function () use ($payload, $actor) {
            /** @var Requisition $sr */
            $sr = Requisition::with(['items.item', 'store', 'department'])
                ->lockForUpdate()
                ->findOrFail($payload['requisition_id']);

            if ($sr->type !== Requisition::TYPE_SR) {
                throw ValidationException::withMessages([
                    'requisition_id' => ['Bulk Stock Issues can only be created from a Store Requisition (SR), not a Purchase Requisition (PR).'],
                ]);
            }

            if ($sr->status === Requisition::STATUS_ISSUED) {
                throw ValidationException::withMessages([
                    'requisition_id' => ["Store Requisition [{$sr->requisition_number}] has already been fully issued."],
                ]);
            }

            if (! in_array($sr->status, [Requisition::STATUS_HOD_APPROVED, Requisition::STATUS_PARTIALLY_ISSUED], true)) {
                throw ValidationException::withMessages([
                    'requisition_id' => ["Only HOD Approved or Partially Issued Store Requisitions can be issued. Current status: {$sr->status}."],
                ]);
            }

            if (array_key_exists('store_id', $payload) && $payload['store_id'] !== null && (int) $payload['store_id'] !== (int) $sr->store_id) {
                throw ValidationException::withMessages([
                    'store_id' => ['Selected source store does not match the Store Requisition source store.'],
                ]);
            }

            if (array_key_exists('department_id', $payload) && $payload['department_id'] !== null && (int) $payload['department_id'] !== (int) $sr->department_id) {
                throw ValidationException::withMessages([
                    'department_id' => ['Selected department does not match the Store Requisition receiving department.'],
                ]);
            }

            // Enforce store scope for store personnel assigned to a specific store
            if (! $actor->hasRole(Role::SLUG_ADMIN) && $actor->store_id !== null && (int) $actor->store_id !== (int) $sr->store_id) {
                throw new AccessDeniedHttpException('You can only issue stock from your assigned store.');
            }

            /** @var Store $store */
            $store = Store::findOrFail($sr->store_id);
            if (! $store->is_active) {
                throw ValidationException::withMessages([
                    'store_id' => ['Cannot issue stock from an inactive store.'],
                ]);
            }

            /** @var Department $department */
            $department = Department::findOrFail($sr->department_id);
            if (! $department->is_active) {
                throw ValidationException::withMessages([
                    'department_id' => ['Cannot issue stock to an inactive department/cost center.'],
                ]);
            }

            $lines = $this->validateIssueItemsAgainstSrAndStock($payload['items'] ?? [], $sr, $store->id);

            $issue = StockIssue::create([
                'issue_number' => $this->generateNextIssueNumber(),
                'requisition_id' => $sr->id,
                'store_id' => $store->id,
                'department_id' => $department->id,
                'created_by' => $actor->id,
                'issued_by' => null,
                'issued_at' => null,
                'status' => StockIssue::STATUS_DRAFT,
                'notes' => $payload['notes'] ?? null,
            ]);

            foreach ($lines as $line) {
                StockIssueItem::create([
                    'stock_issue_id' => $issue->id,
                    'requisition_item_id' => $line['requisition_item_id'],
                    'item_id' => $line['item_id'],
                    'issued_quantity' => $line['issued_quantity'],
                    'unit_cost' => $line['unit_cost'],
                    'line_cost' => $line['line_cost'],
                    'uom' => $line['uom'],
                    'notes' => $line['notes'],
                ]);
            }

            return $issue;
        });

        if (! empty($payload['post_immediately'])) {
            return $this->postIssue($issue, $actor);
        }

        return $issue->load([
            'requisition.department',
            'store',
            'department',
            'creator',
            'issuer',
            'items.item',
            'items.requisitionItem',
            'stockLedgerEntries.actor',
        ]);
    }

    /**
     * Atomically post/approve a Draft Stock Issue:
     * - Locks StockIssue, Requisition, RequisitionItems, and CurrentStocks rows
     * - Re-validates SR remaining quantities and sufficient available stock (no negative balances)
     * - Decreases current_stocks for the source store
     * - Inserts StockLedger OUT entries attributed to the receiving department/cost center
     * - Updates SR issued quantities and transitions SR status to Partially Issued or Issued
     * - Marks StockIssue as Posted with actor and timestamp
     */
    public function postIssue(StockIssue $issue, User $actor): StockIssue
    {
        if (! $actor->hasRole([Role::SLUG_STORE_KEEPER, Role::SLUG_STORE_MANAGER, Role::SLUG_ADMIN])) {
            throw new AccessDeniedHttpException('Only Store Keepers, Store Managers, or Administrators can post Stock Issues.');
        }

        return DB::transaction(function () use ($issue, $actor) {
            /** @var StockIssue $lockedIssue */
            $lockedIssue = StockIssue::with(['items.item'])
                ->lockForUpdate()
                ->findOrFail($issue->id);

            if (! $actor->hasRole(Role::SLUG_ADMIN) && $actor->store_id !== null && (int) $actor->store_id !== (int) $lockedIssue->store_id) {
                throw new AccessDeniedHttpException('You can only post Stock Issues for your assigned store.');
            }

            if ($lockedIssue->status !== StockIssue::STATUS_DRAFT) {
                throw ValidationException::withMessages([
                    'status' => ["Only Draft Stock Issues can be posted. Current status: {$lockedIssue->status}."],
                ]);
            }

            /** @var Requisition $sr */
            $sr = Requisition::lockForUpdate()->findOrFail($lockedIssue->requisition_id);

            if ($sr->type !== Requisition::TYPE_SR) {
                throw ValidationException::withMessages([
                    'requisition_id' => ['Cannot post Stock Issue against a non-SR requisition.'],
                ]);
            }

            if ($sr->status === Requisition::STATUS_ISSUED) {
                throw ValidationException::withMessages([
                    'requisition_id' => ["Store Requisition [{$sr->requisition_number}] has already been fully issued."],
                ]);
            }

            if (! in_array($sr->status, [Requisition::STATUS_HOD_APPROVED, Requisition::STATUS_PARTIALLY_ISSUED], true)) {
                throw ValidationException::withMessages([
                    'requisition_id' => ["Cannot post Stock Issue because Store Requisition status is [{$sr->status}]."],
                ]);
            }

            foreach ($lockedIssue->items as $issueItem) {
                /** @var RequisitionItem $srItem */
                $srItem = RequisitionItem::lockForUpdate()->findOrFail($issueItem->requisition_item_id);

                $requestedQty = (float) $srItem->requested_quantity;
                $alreadyIssuedQty = (float) $srItem->issued_quantity;
                $remainingSrQty = round($requestedQty - $alreadyIssuedQty, 3);
                $currentIssueQty = round((float) $issueItem->issued_quantity, 3);

                if ($currentIssueQty <= 0) {
                    throw ValidationException::withMessages([
                        'items' => ['Issued quantity must be greater than zero.'],
                    ]);
                }

                if ($currentIssueQty > $remainingSrQty + 0.0001) {
                    throw ValidationException::withMessages([
                        'items' => ["Cannot post Stock Issue: quantity ({$currentIssueQty}) for item [{$issueItem->item->sku}] exceeds remaining SR quantity ({$remainingSrQty})."],
                    ]);
                }

                /** @var CurrentStock|null $currentStock */
                $currentStock = CurrentStock::where('item_id', $issueItem->item_id)
                    ->where('store_id', $lockedIssue->store_id)
                    ->lockForUpdate()
                    ->first();

                $availableStock = $currentStock ? (float) $currentStock->quantity : 0.0;

                if ($currentIssueQty > $availableStock + 0.0001) {
                    throw ValidationException::withMessages([
                        'items' => ["Insufficient stock for item [{$issueItem->item->sku}]. Available: {$availableStock}, Attempted issue: {$currentIssueQty}."],
                    ]);
                }

                // 1. Atomically decrease CurrentStock
                $newStockBalance = round($availableStock - $currentIssueQty, 3);
                $currentStock->update([
                    'quantity' => $newStockBalance,
                ]);

                // 2. Update SR item issued_quantity
                $srItem->update([
                    'issued_quantity' => round($alreadyIssuedQty + $currentIssueQty, 3),
                ]);

                // 3. Refresh cost snapshot from catalog item
                /** @var Item $catalogItem */
                $catalogItem = Item::lockForUpdate()->findOrFail($issueItem->item_id);
                $unitCost = round((float) $catalogItem->unit_cost, 4);
                $lineCost = round($currentIssueQty * $unitCost, 4);

                $issueItem->update([
                    'unit_cost' => $unitCost,
                    'line_cost' => $lineCost,
                ]);

                // 4. Insert authoritative StockLedger OUT entry with department/cost center attribution
                StockLedger::create([
                    'item_id' => $issueItem->item_id,
                    'store_id' => $lockedIssue->store_id,
                    'department_id' => $lockedIssue->department_id,
                    'transaction_type' => StockLedger::TYPE_OUT,
                    'quantity' => -$currentIssueQty,
                    'balance_after' => $newStockBalance,
                    'reference_type' => StockLedger::REF_STOCK_ISSUE,
                    'reference_id' => $lockedIssue->id,
                    'reference_number' => $lockedIssue->issue_number,
                    'created_by' => $actor->id,
                    'transaction_at' => now(),
                    'notes' => $issueItem->notes ?? $lockedIssue->notes ?? "Stock Issue {$lockedIssue->issue_number} for SR {$sr->requisition_number}",
                    'metadata' => [
                        'requisition_id' => $sr->id,
                        'requisition_number' => $sr->requisition_number,
                        'stock_issue_item_id' => $issueItem->id,
                        'issued_quantity' => number_format($currentIssueQty, 3, '.', ''),
                        'unit_cost' => number_format($unitCost, 4, '.', ''),
                        'line_cost' => number_format($lineCost, 4, '.', ''),
                    ],
                ]);
            }

            // 5. Derive SR status (Partially Issued vs Issued)
            $allSrItems = RequisitionItem::where('requisition_id', $sr->id)->lockForUpdate()->get();
            $allFullyIssued = $allSrItems->every(function (RequisitionItem $item) {
                return (float) $item->issued_quantity >= (float) $item->requested_quantity - 0.0001;
            });

            $sr->update([
                'status' => $allFullyIssued
                    ? Requisition::STATUS_ISSUED
                    : Requisition::STATUS_PARTIALLY_ISSUED,
            ]);

            // 6. Mark StockIssue as Posted
            $lockedIssue->update([
                'status' => StockIssue::STATUS_POSTED,
                'issued_by' => $actor->id,
                'issued_at' => now(),
            ]);

            return $lockedIssue->fresh([
                'requisition.department',
                'store',
                'department',
                'creator',
                'issuer',
                'items.item',
                'items.requisitionItem',
                'stockLedgerEntries.actor',
            ]);
        });
    }

    /**
     * Validate line items against the SR items and current available store stock.
     */
    protected function validateIssueItemsAgainstSrAndStock(array $rawItems, Requisition $sr, int $storeId): array
    {
        if (empty($rawItems)) {
            throw ValidationException::withMessages([
                'items' => ['A Stock Issue must include at least one item line.'],
            ]);
        }

        $srItemsByItemId = $sr->items->keyBy('item_id');
        $seenItemIds = [];
        $validatedLines = [];

        foreach ($rawItems as $index => $line) {
            $itemId = (int) ($line['item_id'] ?? 0);
            $issuedQty = isset($line['issued_quantity']) ? round((float) $line['issued_quantity'], 3) : 0.0;

            if ($issuedQty <= 0) {
                throw ValidationException::withMessages([
                    "items.{$index}.issued_quantity" => ['Issued quantity must be greater than zero.'],
                ]);
            }

            if (in_array($itemId, $seenItemIds, true)) {
                throw ValidationException::withMessages([
                    "items.{$index}.item_id" => ['Duplicate item in the same Stock Issue is not allowed.'],
                ]);
            }
            $seenItemIds[] = $itemId;

            /** @var RequisitionItem|null $srItem */
            $srItem = $srItemsByItemId->get($itemId);
            if (! $srItem) {
                throw ValidationException::withMessages([
                    "items.{$index}.item_id" => ['Selected item does not belong to the linked Store Requisition.'],
                ]);
            }

            $remainingSrQty = round((float) $srItem->requested_quantity - (float) $srItem->issued_quantity, 3);
            if ($issuedQty > $remainingSrQty + 0.0001) {
                throw ValidationException::withMessages([
                    "items.{$index}.issued_quantity" => ["Issued quantity ({$issuedQty}) exceeds remaining SR balance ({$remainingSrQty}) for item [{$srItem->item->sku}]."],
                ]);
            }

            $availableStock = (float) (CurrentStock::where('item_id', $itemId)
                ->where('store_id', $storeId)
                ->value('quantity') ?? 0.0);

            if ($issuedQty > $availableStock + 0.0001) {
                throw ValidationException::withMessages([
                    "items.{$index}.issued_quantity" => ["Insufficient stock for item [{$srItem->item->sku}]. Available: {$availableStock}, Requested to issue: {$issuedQty}."],
                ]);
            }

            $unitCost = round((float) ($srItem->item->unit_cost ?? 0.0), 4);
            $lineCost = round($issuedQty * $unitCost, 4);

            $validatedLines[] = [
                'requisition_item_id' => $srItem->id,
                'item_id' => $itemId,
                'issued_quantity' => $issuedQty,
                'unit_cost' => $unitCost,
                'line_cost' => $lineCost,
                'uom' => $srItem->uom,
                'notes' => $line['notes'] ?? null,
            ];
        }

        return $validatedLines;
    }

    /**
     * Generate a unique sequential Stock Issue number (e.g. ISS-202610-0001).
     */
    protected function generateNextIssueNumber(): string
    {
        $yearMonth = now()->format('Ym');
        $prefix = "ISS-{$yearMonth}-";

        $latest = StockIssue::where('issue_number', 'like', "{$prefix}%")
            ->orderByDesc('id')
            ->value('issue_number');

        $nextSeq = 1;
        if ($latest && preg_match('/(\d{4})$/', $latest, $matches)) {
            $nextSeq = ((int) $matches[1]) + 1;
        }

        return sprintf('%s%04d', $prefix, $nextSeq);
    }
}
