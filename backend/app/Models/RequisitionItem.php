<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RequisitionItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'requisition_id',
        'item_id',
        'requested_quantity',
        'issued_quantity',
        'uom',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'requested_quantity' => 'decimal:3',
            'issued_quantity' => 'decimal:3',
        ];
    }

    /**
     * Parent requisition header.
     */
    public function requisition(): BelongsTo
    {
        return $this->belongsTo(Requisition::class);
    }

    /**
     * Requested item/product.
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /**
     * Purchase order lines referencing this requisition item.
     */
    public function poItems(): HasMany
    {
        return $this->hasMany(PoItem::class);
    }
}
