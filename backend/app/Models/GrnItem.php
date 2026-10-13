<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GrnItem extends Model
{
    use HasFactory;

    protected $table = 'grn_items';

    protected $fillable = [
        'grn_id',
        'po_item_id',
        'item_id',
        'received_quantity',
        'unit_price',
        'uom',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'received_quantity' => 'decimal:3',
            'unit_price' => 'decimal:4',
        ];
    }

    /**
     * Parent Goods Receiving Note.
     */
    public function grn(): BelongsTo
    {
        return $this->belongsTo(GoodsReceivingNote::class, 'grn_id');
    }

    /**
     * Alias for parent Goods Receiving Note.
     */
    public function goodsReceivingNote(): BelongsTo
    {
        return $this->belongsTo(GoodsReceivingNote::class, 'grn_id');
    }

    /**
     * Referenced Purchase Order line item.
     */
    public function poItem(): BelongsTo
    {
        return $this->belongsTo(PoItem::class);
    }

    /**
     * Received item/product.
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
