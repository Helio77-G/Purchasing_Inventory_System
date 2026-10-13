<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Store extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'location',
        'description',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * Store staff assigned to this store.
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * Store requisitions targeting this store.
     */
    public function requisitions(): HasMany
    {
        return $this->hasMany(Requisition::class);
    }

    /**
     * Goods receiving notes received into this store.
     */
    public function goodsReceivingNotes(): HasMany
    {
        return $this->hasMany(GoodsReceivingNote::class);
    }

    /**
     * Current stock balances in this store.
     */
    public function currentStocks(): HasMany
    {
        return $this->hasMany(CurrentStock::class);
    }

    /**
     * Historical stock ledger entries for this store.
     */
    public function stockLedgerEntries(): HasMany
    {
        return $this->hasMany(StockLedger::class);
    }
}
