<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class StockIssue extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'Draft';

    public const STATUS_POSTED = 'Posted';

    public const ALLOWED_STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_POSTED,
    ];

    protected $fillable = [
        'issue_number',
        'requisition_id',
        'store_id',
        'department_id',
        'created_by',
        'issued_by',
        'issued_at',
        'status',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
        ];
    }

    /**
     * Source Store Requisition (SR) being fulfilled.
     */
    public function requisition(): BelongsTo
    {
        return $this->belongsTo(Requisition::class);
    }

    /**
     * Source Store from which stock is issued.
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * Receiving Department / Cost Center.
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * User who created the Stock Issue record.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * User who posted/approved the Stock Issue.
     */
    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    /**
     * Line items included in this Stock Issue.
     */
    public function items(): HasMany
    {
        return $this->hasMany(StockIssueItem::class);
    }

    /**
     * Stock ledger OUT entries posted when this Stock Issue was posted.
     */
    public function stockLedgerEntries(): MorphMany
    {
        return $this->morphMany(StockLedger::class, 'reference', 'reference_type', 'reference_id');
    }
}
