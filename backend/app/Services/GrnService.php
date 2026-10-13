<?php

namespace App\Services;

use App\Models\CurrentStock;
use App\Models\GoodsReceivingNote;
use App\Models\GrnItem;
use App\Models\PoItem;
use App\Models\PurchaseOrder;
use App\Models\Role;
use App\Models\StockLedger;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class GrnService
{
    /**
     * Create a Pending Goods Receiving Note (GRN) against an eligible Sent / Partial Received PO.
     * Note: Creating a Pending GRN does NOT modify current_stocks or post stock_ledger entries.
     *
     * @param  array<string, mixed>  $data
     */
    public function createGrn(User $actor, array $data): GoodsReceivingNote
    {
        return DB::transaction(function () use ($actor, $data) {
            if (! $actor->hasRole([Role::SLUG_STORE_KEEPER, Role::SLUG_STORE_MANAGER, Role::SLUG_ADMIN])) {
                throw new AccessDeniedHttpException('Only authorized store personnel can record a Goods Receiving Note.');
            }

            $storeId = (int) $data['store_id'];
            if ($actor->store_id !== null && (int) $actor->store_id !== $storeId && ! $actor->hasRole(Role::SLUG_ADMIN)) {
                throw new AccessDeniedHttpException('You can only record goods receipts for your assigned store.');
            }

            $store = Store::where('id', $storeId)->where('is_active', true)->first();
            if (! $store) {
                throw ValidationException::withMessages([
                    'store_id' => ['Selected receiving store does not exist or is inactive.'],
                ]);
            }

            /** @var PurchaseOrder|null $po */
            $po = PurchaseOrder::with('items.item')
                ->where('id', (int) $data['purchase_order_id'])
                ->lockForUpdate()
                ->first();

            if (! $po) {
                throw ValidationException::withMessages([
                    'purchase_order_id' => ['Selected Purchase Order does not exist.'],
                ]);
            }

            if (! in_array($po->status, [PurchaseOrder::STATUS_SENT, PurchaseOrder::STATUS_PARTIAL_RECEIVED], true)) {
                throw ValidationException::withMessages([
                    'purchase_order_id' => ["Goods can only be received against a [Sent] or [Partial Received] Purchase Order. Current PO status is [{$po->status}]."],
                ]);
            }

            $rawItems = $data['items'] ?? [];
            if (empty($rawItems)) {
                throw ValidationException::withMessages([
                    'items' => ['At least one received item line is required.'],
                ]);
            }

            $poItemsById = $po->items->keyBy('id');
            $poItemsByItemId = $po->items->keyBy('item_id');
            $seenPoItemIds = [];
            $preparedLines = [];

            foreach ($rawItems as $index => $line) {
                $poItem = null;
                if (! empty($line['po_item_id'])) {
                    $poItem = $poItemsById->get((int) $line['po_item_id']);
                } elseif (! empty($line['item_id'])) {
                    $poItem = $poItemsByItemId->get((int) $line['item_id']);
                }

                if (! $poItem) {
                    throw ValidationException::withMessages([
                        "items.{$index}.po_item_id" => ['Referenced item is not part of the selected Purchase Order.'],
                    ]);
                }

                if (in_array($poItem->id, $seenPoItemIds, true)) {
                    throw ValidationException::withMessages([
                        "items.{$index}.po_item_id" => ['Duplicate Purchase Order line items are not allowed in the same GRN.'],
                    ]);
                }

                $qty = isset($line['received_quantity']) && is_numeric($line['received_quantity'])
                    ? (float) $line['received_quantity']
                    : 0.0;

                if ($qty <= 0) {
                    throw ValidationException::withMessages([
                        "items.{$index}.received_quantity" => ['Received quantity must be greater than zero.'],
                    ]);
                }

                $formattedQty = number_format(round($qty, 3), 3, '.', '');
                $remainingReceivable = bcsub((string) $poItem->ordered_quantity, (string) $poItem->received_quantity, 3);

                if (bccomp($formattedQty, $remainingReceivable, 3) === 1) {
                    throw ValidationException::withMessages([
                        "items.{$index}.received_quantity" => [
                            "Over-receipt rejected for item [{$poItem->item->sku}]: received quantity ({$formattedQty}) exceeds remaining PO balance ({$remainingReceivable}).",
                        ],
                    ]);
                }

                $seenPoItemIds[] = $poItem->id;
                $preparedLines[] = [
                    'po_item' => $poItem,
                    'received_quantity' => $formattedQty,
                    'notes' => isset($line['notes']) ? trim((string) $line['notes']) : null,
                ];
            }

            $grn = GoodsReceivingNote::create([
                'grn_number' => $this->generateGrnNumber(),
                'purchase_order_id' => $po->id,
                'store_id' => $store->id,
                'received_by' => $actor->id,
                'received_at' => now(),
                'status' => GoodsReceivingNote::STATUS_PENDING,
                'delivery_note_number' => $data['delivery_note_number'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);

            foreach ($preparedLines as $line) {
                /** @var PoItem $poItem */
                $poItem = $line['po_item'];
                GrnItem::create([
                    'grn_id' => $grn->id,
                    'po_item_id' => $poItem->id,
                    'item_id' => $poItem->item_id,
                    'received_quantity' => $line['received_quantity'],
                    'unit_price' => $poItem->unit_price,
                    'uom' => $poItem->uom,
                    'notes' => $line['notes'],
                ]);
            }

            return $grn->load([
                'purchaseOrder.supplier',
                'store',
                'receiver.role',
                'approver.role',
                'rejector.role',
                'items.item',
                'items.poItem',
            ]);
        });
    }

    /**
     * Approve/Post a Pending GRN atomically in a single DB::transaction.
     * - Restricts approval strictly to authorized Store Managers.
     * - Locks GRN, PO, PO items, and CurrentStock rows.
     * - Re-validates remaining PO quantities to prevent concurrent over-receipt.
     * - Inserts exactly one stock_ledger IN entry per GRN line.
     * - Increments current_stocks atomically.
     * - Updates PO received quantities and derives PO status (Partial Received / Fully Received).
     */
    public function approveGrn(User $actor, GoodsReceivingNote $grn): GoodsReceivingNote
    {
        if (! $actor->hasRole(Role::SLUG_STORE_MANAGER)) {
            throw new AccessDeniedHttpException('Only an authorized Store Manager can approve and post a Goods Receiving Note.');
        }

        return DB::transaction(function () use ($actor, $grn) {
            /** @var GoodsReceivingNote $lockedGrn */
            $lockedGrn = GoodsReceivingNote::with('items.item')
                ->where('id', $grn->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($actor->store_id !== null && (int) $actor->store_id !== (int) $lockedGrn->store_id) {
                throw new AccessDeniedHttpException('You can only approve GRNs for your assigned store.');
            }

            if ($lockedGrn->status !== GoodsReceivingNote::STATUS_PENDING) {
                throw ValidationException::withMessages([
                    'status' => ["GRN [{$lockedGrn->grn_number}] cannot be approved from status [{$lockedGrn->status}]. Expected [Pending]."],
                ]);
            }

            // Double-check idempotency in stock_ledger
            $alreadyPosted = StockLedger::where('reference_type', StockLedger::REF_GRN)
                ->where('reference_id', $lockedGrn->id)
                ->lockForUpdate()
                ->exists();

            if ($alreadyPosted) {
                throw ValidationException::withMessages([
                    'status' => ["GRN [{$lockedGrn->grn_number}] has already been posted to the stock ledger."],
                ]);
            }

            /** @var PurchaseOrder $po */
            $po = PurchaseOrder::where('id', $lockedGrn->purchase_order_id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! in_array($po->status, [PurchaseOrder::STATUS_SENT, PurchaseOrder::STATUS_PARTIAL_RECEIVED], true)) {
                throw ValidationException::withMessages([
                    'purchase_order_id' => ["Cannot post GRN because Purchase Order [{$po->po_number}] has status [{$po->status}]."],
                ]);
            }

            $lockedPoItems = PoItem::where('purchase_order_id', $po->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $now = now();

            foreach ($lockedGrn->items as $grnItem) {
                /** @var PoItem|null $poItem */
                $poItem = $lockedPoItems->get($grnItem->po_item_id);
                if (! $poItem) {
                    throw ValidationException::withMessages([
                        'items' => ['GRN line item references an invalid Purchase Order item.'],
                    ]);
                }

                $remainingReceivable = bcsub((string) $poItem->ordered_quantity, (string) $poItem->received_quantity, 3);
                if (bccomp((string) $grnItem->received_quantity, $remainingReceivable, 3) === 1) {
                    throw ValidationException::withMessages([
                        'items' => [
                            "Over-receipt rejected on approval for item ID [{$grnItem->item_id}]: received ({$grnItem->received_quantity}) exceeds remaining PO balance ({$remainingReceivable}).",
                        ],
                    ]);
                }

                // Lock or safely create the CurrentStock row for (item_id, store_id)
                $currentStock = CurrentStock::where('item_id', $grnItem->item_id)
                    ->where('store_id', $lockedGrn->store_id)
                    ->lockForUpdate()
                    ->first();

                if (! $currentStock) {
                    $currentStock = CurrentStock::create([
                        'item_id' => $grnItem->item_id,
                        'store_id' => $lockedGrn->store_id,
                        'quantity' => '0.000',
                    ]);
                    $currentStock = CurrentStock::where('id', $currentStock->id)
                        ->lockForUpdate()
                        ->firstOrFail();
                }

                $newBalance = bcadd((string) $currentStock->quantity, (string) $grnItem->received_quantity, 3);

                $currentStock->update([
                    'quantity' => $newBalance,
                ]);

                // Insert authoritative StockLedger IN movement
                StockLedger::create([
                    'item_id' => $grnItem->item_id,
                    'store_id' => $lockedGrn->store_id,
                    'department_id' => null,
                    'transaction_type' => StockLedger::TYPE_IN,
                    'quantity' => $grnItem->received_quantity,
                    'balance_after' => $newBalance,
                    'reference_type' => StockLedger::REF_GRN,
                    'reference_id' => $lockedGrn->id,
                    'reference_number' => $lockedGrn->grn_number,
                    'created_by' => $actor->id,
                    'transaction_at' => $now,
                    'notes' => "Stock IN from GRN {$lockedGrn->grn_number} (PO {$po->po_number})",
                    'metadata' => [
                        'purchase_order_id' => $po->id,
                        'po_number' => $po->po_number,
                        'po_item_id' => $poItem->id,
                        'grn_item_id' => $grnItem->id,
                        'unit_price' => (string) $grnItem->unit_price,
                    ],
                ]);

                // Update PO item received quantity
                $newPoItemReceived = bcadd((string) $poItem->received_quantity, (string) $grnItem->received_quantity, 3);
                $poItem->update([
                    'received_quantity' => $newPoItemReceived,
                ]);
            }

            // Mark GRN as Approved
            $lockedGrn->update([
                'status' => GoodsReceivingNote::STATUS_APPROVED,
                'approved_by' => $actor->id,
                'approved_at' => $now,
            ]);

            // Derive and update PO status (Partial Received vs Fully Received)
            $refreshedPoItems = PoItem::where('purchase_order_id', $po->id)->get();
            $allFullyReceived = $refreshedPoItems->every(function (PoItem $pi) {
                return bccomp((string) $pi->received_quantity, (string) $pi->ordered_quantity, 3) >= 0;
            });

            $po->update([
                'status' => $allFullyReceived
                    ? PurchaseOrder::STATUS_FULLY_RECEIVED
                    : PurchaseOrder::STATUS_PARTIAL_RECEIVED,
            ]);

            return $lockedGrn->fresh([
                'purchaseOrder.supplier',
                'purchaseOrder.items.item',
                'store',
                'receiver.role',
                'approver.role',
                'rejector.role',
                'items.item',
                'items.poItem',
                'stockLedgerEntries',
            ]);
        });
    }

    /**
     * Reject a Pending GRN without modifying stock.
     */
    public function rejectGrn(User $actor, GoodsReceivingNote $grn, string $reason): GoodsReceivingNote
    {
        if (! $actor->hasRole(Role::SLUG_STORE_MANAGER)) {
            throw new AccessDeniedHttpException('Only an authorized Store Manager can reject a Goods Receiving Note.');
        }

        return DB::transaction(function () use ($actor, $grn, $reason) {
            /** @var GoodsReceivingNote $lockedGrn */
            $lockedGrn = GoodsReceivingNote::where('id', $grn->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($actor->store_id !== null && (int) $actor->store_id !== (int) $lockedGrn->store_id) {
                throw new AccessDeniedHttpException('You can only reject GRNs for your assigned store.');
            }

            if ($lockedGrn->status !== GoodsReceivingNote::STATUS_PENDING) {
                throw ValidationException::withMessages([
                    'status' => ["Only [Pending] GRNs can be rejected. Current status is [{$lockedGrn->status}]."],
                ]);
            }

            $lockedGrn->update([
                'status' => GoodsReceivingNote::STATUS_REJECTED,
                'rejected_by' => $actor->id,
                'rejected_at' => now(),
                'rejection_reason' => trim($reason),
            ]);

            return $lockedGrn->fresh([
                'purchaseOrder.supplier',
                'store',
                'receiver.role',
                'approver.role',
                'rejector.role',
                'items.item',
                'items.poItem',
            ]);
        });
    }

    /**
     * Generate a unique sequential GRN number (e.g., GRN-202610-0001).
     */
    private function generateGrnNumber(): string
    {
        $prefix = 'GRN-'.now()->format('Ym').'-';

        $latest = GoodsReceivingNote::where('grn_number', 'like', $prefix.'%')
            ->orderByDesc('id')
            ->value('grn_number');

        $nextSeq = 1;
        if ($latest && preg_match('/-(\d+)$/', $latest, $matches)) {
            $nextSeq = ((int) $matches[1]) + 1;
        }

        return $prefix.str_pad((string) $nextSeq, 4, '0', STR_PAD_LEFT);
    }
}
