<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class StockTake extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'Draft';

    public const STATUS_APPROVED = 'Approved';

    public const STATUS_CANCELLED = 'Cancelled';

    public const ALLOWED_STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_APPROVED,
        self::STATUS_CANCELLED,
    ];

    protected $fillable = [
        'stock_take_number',
        'store_id',
        'status',
        'created_by',
        'counted_by',
        'counted_at',
        'approved_by',
        'approved_at',
        'reason',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'counted_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    /**
     * Store/Warehouse where physical stock take was performed.
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * User who initiated the stock take session.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * User who performed the physical counting.
     */
    public function counter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'counted_by');
    }

    /**
     * User (Store Manager / Admin) who approved and posted the stock adjustment.
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Line items in this stock take session.
     */
    public function items(): HasMany
    {
        return $this->hasMany(StockTakeItem::class);
    }

    /**
     * Stock ledger ADJ entries posted when this stock take adjustment was approved.
     */
    public function stockLedgerEntries(): MorphMany
    {
        return $this->morphMany(StockLedger::class, 'reference', 'reference_type', 'reference_id');
    }
}
