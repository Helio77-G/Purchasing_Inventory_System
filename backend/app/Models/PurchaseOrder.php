<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseOrder extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'Draft';
    public const STATUS_SENT = 'Sent';
    public const STATUS_PARTIAL_RECEIVED = 'Partial Received';
    public const STATUS_FULLY_RECEIVED = 'Fully Received';
    public const STATUS_CANCELLED = 'Cancelled';

    public const ALLOWED_STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_SENT,
        self::STATUS_PARTIAL_RECEIVED,
        self::STATUS_FULLY_RECEIVED,
        self::STATUS_CANCELLED,
    ];

    protected $fillable = [
        'po_number',
        'requisition_id',
        'supplier_id',
        'created_by',
        'sent_by',
        'sent_at',
        'status',
        'total_amount',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'total_amount' => 'decimal:4',
        ];
    }

    /**
     * Originating approved Purchase Requisition (PR).
     */
    public function requisition(): BelongsTo
    {
        return $this->belongsTo(Requisition::class);
    }

    /**
     * Selected vendor/supplier.
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * User who created the PO.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * User who sent the PO.
     */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    /**
     * Line items belonging to this PO.
     */
    public function items(): HasMany
    {
        return $this->hasMany(PoItem::class);
    }

    /**
     * Goods receiving notes recorded against this PO.
     */
    public function goodsReceivingNotes(): HasMany
    {
        return $this->hasMany(GoodsReceivingNote::class);
    }
}
