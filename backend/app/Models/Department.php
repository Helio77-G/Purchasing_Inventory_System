<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Department extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
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
     * Users belonging to this department.
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * Requisitions (SR/PR) raised by this department.
     */
    public function requisitions(): HasMany
    {
        return $this->hasMany(Requisition::class);
    }

    /**
     * Stock ledger movements allocated to this department/cost center.
     */
    public function stockLedgerEntries(): HasMany
    {
        return $this->hasMany(StockLedger::class);
    }
}
