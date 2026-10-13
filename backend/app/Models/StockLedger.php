<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class StockLedger extends Model
{
    use HasFactory;

    protected $table = 'stock_ledger';

    public const TYPE_IN = 'IN';
    public const TYPE_OUT = 'OUT';
    public const TYPE_ADJ = 'ADJ';

    public const ALLOWED_TRANSACTION_TYPES = [
        self::TYPE_IN,
        self::TYPE_OUT,
        self::TYPE_ADJ,
    ];

    public const REF_GRN = 'GRN';
    public const REF_STOCK_ISSUE = 'STOCK_ISSUE';
    public const REF_STOCK_ADJUSTMENT = 'STOCK_ADJUSTMENT';

    protected $fillable = [
        'item_id',
        'store_id',
        'department_id',
        'transaction_type',
        'quantity',
        'balance_after',
        'reference_type',
        'reference_id',
        'reference_number',
        'created_by',
        'transaction_at',
        'notes',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'balance_after' => 'decimal:3',
            'transaction_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /**
     * Item/product moved in this transaction.
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /**
     * Store where the movement occurred.
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * Receiving department / cost center (for OUT movements).
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * User who posted this stock movement.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Alias for user/actor who posted this stock movement.
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Polymorphic reference to the originating source document.
     */
    public function reference(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'reference_type', 'reference_id');
    }
}
