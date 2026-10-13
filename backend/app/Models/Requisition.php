<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Requisition extends Model
{
    use HasFactory;

    public const TYPE_SR = 'SR';
    public const TYPE_PR = 'PR';

    public const STATUS_PENDING = 'Pending';
    public const STATUS_HOD_APPROVED = 'HOD Approved';
    public const STATUS_GM_APPROVED = 'GM Approved';
    public const STATUS_PO_GENERATED = 'PO Generated';
    public const STATUS_PARTIALLY_ISSUED = 'Partially Issued';
    public const STATUS_ISSUED = 'Issued';
    public const STATUS_REJECTED = 'Rejected';

    public const ALLOWED_TYPES = [
        self::TYPE_SR,
        self::TYPE_PR,
    ];

    public const ALLOWED_SR_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_HOD_APPROVED,
        self::STATUS_PARTIALLY_ISSUED,
        self::STATUS_ISSUED,
        self::STATUS_REJECTED,
    ];

    public const ALLOWED_PR_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_HOD_APPROVED,
        self::STATUS_GM_APPROVED,
        self::STATUS_PO_GENERATED,
        self::STATUS_REJECTED,
    ];

    protected $fillable = [
        'requisition_number',
        'type',
        'department_id',
        'store_id',
        'requester_id',
        'status',
        'hod_approved_by',
        'hod_approved_at',
        'gm_approved_by',
        'gm_approved_at',
        'rejected_by',
        'rejected_at',
        'rejection_reason',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'hod_approved_at' => 'datetime',
            'gm_approved_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    /**
     * Requesting department / cost center.
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * Source/target store for the requisition.
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * User who submitted the requisition.
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    /**
     * HOD who approved the requisition.
     */
    public function hodApprover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'hod_approved_by');
    }

    /**
     * GM who approved the PR.
     */
    public function gmApprover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'gm_approved_by');
    }

    /**
     * User who rejected the requisition.
     */
    public function rejector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    /**
     * Line items belonging to this requisition.
     */
    public function items(): HasMany
    {
        return $this->hasMany(RequisitionItem::class);
    }

    /**
     * Purchase orders generated from this requisition (when type = PR).
     */
    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }
}
