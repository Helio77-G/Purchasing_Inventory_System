<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Item extends Model
{
    use HasFactory;

    public const ALLOWED_UOMS = [
        'PCS',
        'BOX',
        'KG',
        'G',
        'L',
        'ML',
        'BTL',
        'PACK',
        'BAG',
        'TIN',
        'CASE',
        'ROLL',
        'SET',
        'DOZ',
        'PAIR',
    ];

    protected $fillable = [
        'sku',
        'name',
        'category',
        'uom',
        'min_par_level',
        'description',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'min_par_level' => 'decimal:3',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Requisition line items referencing this product.
     */
    public function requisitionItems(): HasMany
    {
        return $this->hasMany(RequisitionItem::class);
    }

    /**
     * Purchase order line items referencing this product.
     */
    public function poItems(): HasMany
    {
        return $this->hasMany(PoItem::class);
    }

    /**
     * GRN line items referencing this product.
     */
    public function grnItems(): HasMany
    {
        return $this->hasMany(GrnItem::class);
    }

    /**
     * Current stock balances across stores for this item.
     */
    public function currentStocks(): HasMany
    {
        return $this->hasMany(CurrentStock::class);
    }

    /**
     * Stock ledger entries for this item.
     */
    public function stockLedgerEntries(): HasMany
    {
        return $this->hasMany(StockLedger::class);
    }
}
