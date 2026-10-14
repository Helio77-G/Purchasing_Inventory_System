<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockTakeItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'stock_take_id',
        'item_id',
        'system_quantity',
        'physical_quantity',
        'variance_quantity',
        'unit_cost',
        'variance_cost',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'system_quantity' => 'decimal:3',
            'physical_quantity' => 'decimal:3',
            'variance_quantity' => 'decimal:3',
            'unit_cost' => 'decimal:4',
            'variance_cost' => 'decimal:4',
        ];
    }

    /**
     * Parent stock take session.
     */
    public function stockTake(): BelongsTo
    {
        return $this->belongsTo(StockTake::class);
    }

    /**
     * Catalog item being counted.
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
