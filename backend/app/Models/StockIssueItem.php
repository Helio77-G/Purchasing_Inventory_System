<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockIssueItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'stock_issue_id',
        'requisition_item_id',
        'item_id',
        'issued_quantity',
        'unit_cost',
        'line_cost',
        'uom',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'issued_quantity' => 'decimal:3',
            'unit_cost' => 'decimal:4',
            'line_cost' => 'decimal:4',
        ];
    }

    /**
     * Parent Stock Issue document.
     */
    public function stockIssue(): BelongsTo
    {
        return $this->belongsTo(StockIssue::class);
    }

    /**
     * Originating SR line item.
     */
    public function requisitionItem(): BelongsTo
    {
        return $this->belongsTo(RequisitionItem::class);
    }

    /**
     * Issued catalog item.
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
