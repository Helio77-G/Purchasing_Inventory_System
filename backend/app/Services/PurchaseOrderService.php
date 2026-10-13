<?php

namespace App\Services;

use App\Models\Item;
use App\Models\PoItem;
use App\Models\PurchaseOrder;
use App\Models\Requisition;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PurchaseOrderService
{
    /**
     * Create a new Draft Purchase Order from an eligible GM-Approved Purchase Requisition (PR).
     * Never trusts client totals and never posts stock movements.
     *
     * @param  array<string, mixed>  $data
     */
    public function createFromRequisition(User $actor, array $data): PurchaseOrder
    {
        return DB::transaction(function () use ($actor, $data) {
            $requisitionId = (int) $data['requisition_id'];

            /** @var Requisition|null $pr */
            $pr = Requisition::with('items')
                ->where('id', $requisitionId)
                ->lockForUpdate()
                ->first();

            if (! $pr) {
                throw ValidationException::withMessages([
                    'requisition_id' => ['Selected Purchase Requisition does not exist.'],
                ]);
            }

            if ($pr->type !== Requisition::TYPE_PR) {
                throw ValidationException::withMessages([
                    'requisition_id' => ['Purchase Orders can only be generated from a Purchase Requisition (PR), not a Store Requisition (SR).'],
                ]);
            }

            // Check for duplicate active PO first so we give a specific error message if a PO already exists
            $existingActivePo = PurchaseOrder::where('requisition_id', $pr->id)
                ->where('status', '!=', PurchaseOrder::STATUS_CANCELLED)
                ->lockForUpdate()
                ->exists();

            if ($existingActivePo || $pr->status === Requisition::STATUS_PO_GENERATED) {
                throw ValidationException::withMessages([
                    'requisition_id' => ['An active Purchase Order has already been generated for this Purchase Requisition.'],
                ]);
            }

            if ($pr->status !== Requisition::STATUS_GM_APPROVED) {
                throw ValidationException::withMessages([
                    'requisition_id' => ["Purchase Requisition must be [GM Approved] before generating a PO. Current status is [{$pr->status}]."],
                ]);
            }

            $supplier = Supplier::where('id', (int) $data['supplier_id'])
                ->where('is_active', true)
                ->first();

            if (! $supplier) {
                throw ValidationException::withMessages([
                    'supplier_id' => ['Selected supplier does not exist or is inactive.'],
                ]);
            }

            $preparedLines = $this->validateAndCalculateLines($pr, $data['items'] ?? []);

            $po = PurchaseOrder::create([
                'po_number' => $this->generatePoNumber(),
                'requisition_id' => $pr->id,
                'supplier_id' => $supplier->id,
                'created_by' => $actor->id,
                'status' => PurchaseOrder::STATUS_DRAFT,
                'total_amount' => $preparedLines['grand_total'],
                'notes' => $data['notes'] ?? null,
            ]);

            foreach ($preparedLines['lines'] as $line) {
                PoItem::create([
                    'purchase_order_id' => $po->id,
                    'requisition_item_id' => $line['requisition_item_id'],
                    'item_id' => $line['item']->id,
                    'ordered_quantity' => $line['ordered_quantity'],
                    'received_quantity' => '0.000',
                    'unit_price' => $line['unit_price'],
                    'line_total' => $line['line_total'],
                    'uom' => $line['item']->uom,
                    'notes' => $line['notes'],
                ]);
            }

            // Transition PR status to PO Generated atomically
            $pr->update([
                'status' => Requisition::STATUS_PO_GENERATED,
            ]);

            return $po->load([
                'requisition.department',
                'supplier',
                'creator.role',
                'sender.role',
                'items.item',
                'items.requisitionItem',
            ]);
        });
    }

    /**
     * Update an existing Draft Purchase Order.
     * Edits are prohibited once a PO is Sent, Partial Received, Fully Received, or Cancelled.
     *
     * @param  array<string, mixed>  $data
     */
    public function updatePurchaseOrder(User $actor, PurchaseOrder $purchaseOrder, array $data): PurchaseOrder
    {
        return DB::transaction(function () use ($purchaseOrder, $data) {
            /** @var PurchaseOrder $locked */
            $locked = PurchaseOrder::with('requisition.items')
                ->where('id', $purchaseOrder->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status !== PurchaseOrder::STATUS_DRAFT) {
                throw ValidationException::withMessages([
                    'status' => ["Only [Draft] Purchase Orders can be edited. Current status is [{$locked->status}]."],
                ]);
            }

            $supplierId = isset($data['supplier_id']) ? (int) $data['supplier_id'] : (int) $locked->supplier_id;
            $supplier = Supplier::where('id', $supplierId)->where('is_active', true)->first();

            if (! $supplier) {
                throw ValidationException::withMessages([
                    'supplier_id' => ['Selected supplier does not exist or is inactive.'],
                ]);
            }

            $preparedLines = $this->validateAndCalculateLines($locked->requisition, $data['items'] ?? []);

            $locked->update([
                'supplier_id' => $supplier->id,
                'total_amount' => $preparedLines['grand_total'],
                'notes' => array_key_exists('notes', $data) ? $data['notes'] : $locked->notes,
            ]);

            $locked->items()->delete();

            foreach ($preparedLines['lines'] as $line) {
                PoItem::create([
                    'purchase_order_id' => $locked->id,
                    'requisition_item_id' => $line['requisition_item_id'],
                    'item_id' => $line['item']->id,
                    'ordered_quantity' => $line['ordered_quantity'],
                    'received_quantity' => '0.000',
                    'unit_price' => $line['unit_price'],
                    'line_total' => $line['line_total'],
                    'uom' => $line['item']->uom,
                    'notes' => $line['notes'],
                ]);
            }

            return $locked->fresh([
                'requisition.department',
                'supplier',
                'creator.role',
                'sender.role',
                'items.item',
                'items.requisitionItem',
            ]);
        });
    }

    /**
     * Transition a Draft Purchase Order to Sent.
     * Note: Does NOT post stock movements. Stock IN occurs only on GRN approval in Part 5.
     */
    public function sendPurchaseOrder(User $actor, PurchaseOrder $purchaseOrder): PurchaseOrder
    {
        return DB::transaction(function () use ($actor, $purchaseOrder) {
            /** @var PurchaseOrder $locked */
            $locked = PurchaseOrder::where('id', $purchaseOrder->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status !== PurchaseOrder::STATUS_DRAFT) {
                throw ValidationException::withMessages([
                    'status' => ["Purchase Order can only be sent from [Draft] status. Current status is [{$locked->status}]."],
                ]);
            }

            if ($locked->items()->count() === 0) {
                throw ValidationException::withMessages([
                    'items' => ['Cannot send a Purchase Order with no line items.'],
                ]);
            }

            $locked->update([
                'status' => PurchaseOrder::STATUS_SENT,
                'sent_by' => $actor->id,
                'sent_at' => now(),
            ]);

            return $locked->fresh([
                'requisition.department',
                'supplier',
                'creator.role',
                'sender.role',
                'items.item',
                'items.requisitionItem',
            ]);
        });
    }

    /**
     * Validate PO line items, verify item references, and compute line totals and grand total on the server.
     *
     * @param  array<int, array<string, mixed>>  $rawItems
     * @return array{lines: array<int, array<string, mixed>>, grand_total: string}
     */
    private function validateAndCalculateLines(?Requisition $pr, array $rawItems): array
    {
        if (empty($rawItems)) {
            throw ValidationException::withMessages([
                'items' => ['At least one Purchase Order line item is required.'],
            ]);
        }

        $prItemsByItemId = $pr ? $pr->items->keyBy('item_id') : collect();
        $seenItemIds = [];
        $lines = [];
        $grandTotal = '0.0000';

        foreach ($rawItems as $index => $line) {
            $itemId = isset($line['item_id']) ? (int) $line['item_id'] : 0;
            $qty = isset($line['ordered_quantity']) ? (float) $line['ordered_quantity'] : 0.0;
            $price = isset($line['unit_price']) && is_numeric($line['unit_price']) ? (float) $line['unit_price'] : -1.0;

            if ($itemId <= 0) {
                throw ValidationException::withMessages([
                    "items.{$index}.item_id" => ['A valid item is required.'],
                ]);
            }

            if (in_array($itemId, $seenItemIds, true)) {
                throw ValidationException::withMessages([
                    "items.{$index}.item_id" => ['Duplicate items are not allowed in the same Purchase Order.'],
                ]);
            }

            if ($qty <= 0) {
                throw ValidationException::withMessages([
                    "items.{$index}.ordered_quantity" => ['Ordered quantity must be greater than zero.'],
                ]);
            }

            if ($price <= 0) {
                throw ValidationException::withMessages([
                    "items.{$index}.unit_price" => ['Unit price must be a positive number greater than zero.'],
                ]);
            }

            $item = Item::where('id', $itemId)->where('is_active', true)->first();
            if (! $item) {
                throw ValidationException::withMessages([
                    "items.{$index}.item_id" => ['Selected item does not exist or is inactive.'],
                ]);
            }

            if ($pr && ! $prItemsByItemId->has($itemId)) {
                throw ValidationException::withMessages([
                    "items.{$index}.item_id" => ["Item [{$item->sku}] is not part of the originating Purchase Requisition [{$pr->requisition_number}]."],
                ]);
            }

            $reqItem = $prItemsByItemId->get($itemId);

            $formattedQty = number_format(round($qty, 3), 3, '.', '');
            $formattedPrice = number_format(round($price, 4), 4, '.', '');
            $lineTotal = bcmul($formattedQty, $formattedPrice, 4);
            $grandTotal = bcadd($grandTotal, $lineTotal, 4);

            $seenItemIds[] = $itemId;
            $lines[] = [
                'item' => $item,
                'requisition_item_id' => $reqItem?->id,
                'ordered_quantity' => $formattedQty,
                'unit_price' => $formattedPrice,
                'line_total' => $lineTotal,
                'notes' => isset($line['notes']) ? trim((string) $line['notes']) : null,
            ];
        }

        return [
            'lines' => $lines,
            'grand_total' => $grandTotal,
        ];
    }

    /**
     * Generate a unique sequential PO number (e.g., PO-202610-0001).
     */
    private function generatePoNumber(): string
    {
        $prefix = 'PO-'.now()->format('Ym').'-';

        $latest = PurchaseOrder::where('po_number', 'like', $prefix.'%')
            ->orderByDesc('id')
            ->value('po_number');

        $nextSeq = 1;
        if ($latest && preg_match('/-(\d+)$/', $latest, $matches)) {
            $nextSeq = ((int) $matches[1]) + 1;
        }

        return $prefix.str_pad((string) $nextSeq, 4, '0', STR_PAD_LEFT);
    }
}
