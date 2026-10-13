<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CurrentStock extends Model
{
    use HasFactory;

    protected $table = 'current_stocks';

    protected $fillable = [
        'item_id',
        'store_id',
        'quantity',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
        ];
    }

    /**
     * Item/Product whose balance is tracked.
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /**
     * Store holding this stock balance.
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
