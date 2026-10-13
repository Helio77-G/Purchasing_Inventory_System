<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role_id',
        'department_id',
        'store_id',
        'is_active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Role assigned to this user.
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * Department assigned to this user.
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * Store assigned to this user (for store managers/keepers).
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * Requisitions requested by this user.
     */
    public function requisitions(): HasMany
    {
        return $this->hasMany(Requisition::class, 'requester_id');
    }

    /**
     * Purchase orders created by this user.
     */
    public function createdPurchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class, 'created_by');
    }

    /**
     * Goods receiving notes recorded by this user.
     */
    public function receivedGrns(): HasMany
    {
        return $this->hasMany(GoodsReceivingNote::class, 'received_by');
    }

    /**
     * Goods receiving notes approved by this user.
     */
    public function approvedGrns(): HasMany
    {
        return $this->hasMany(GoodsReceivingNote::class, 'approved_by');
    }

    /**
     * Stock ledger movements posted by this user.
     */
    public function stockLedgerEntries(): HasMany
    {
        return $this->hasMany(StockLedger::class, 'created_by');
    }

    /**
     * Check if the user has one of the given role slugs.
     *
     * @param  string|array<int, string>  $slugs
     */
    public function hasRole(string|array $slugs): bool
    {
        $slugs = (array) $slugs;
        $roleSlug = $this->role?->slug;

        return $roleSlug !== null && in_array($roleSlug, $slugs, true);
    }
}
