<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Role extends Model
{
    use HasFactory;

    public const SLUG_ADMIN = 'admin';
    public const SLUG_GM = 'gm';
    public const SLUG_HOD = 'hod';
    public const SLUG_PROCUREMENT = 'procurement_officer';
    public const SLUG_STORE_MANAGER = 'store_manager';
    public const SLUG_STORE_KEEPER = 'store_keeper';
    public const SLUG_DEPARTMENT_STAFF = 'department_staff';

    protected $fillable = [
        'name',
        'slug',
        'description',
    ];

    /**
     * Users assigned to this role.
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
