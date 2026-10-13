<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PoItem extends Model
{
    use HasFactory;

    protected $table = 'po_items';

    protected $fillable = [
        'purchase_order_id',
        'requisition_item_id',
        'item_id',
        'ordered_quantity',
        'received_quantity',
        'unit_price',
        'line_total',
        'uom',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'ordered_quantity' => 'decimal:3',
            'received_quantity' => 'decimal:3',
            'unit_price' => 'decimal:4',
            'line_total' => 'decimal:4',
        ];
    }

    /**
     * Parent purchase order.
     */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    /**
     * Originating requisition line item.
     */
    public function requisitionItem(): BelongsTo
    {
        return $this->belongsTo(RequisitionItem::class);
    }

    /**
     * Ordered item/product.
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /**
     * GRN line items received against this PO item.
     */
    public function grnItems(): HasMany
    {
        return $this->hasMany(GrnItem::class);
    }
}
