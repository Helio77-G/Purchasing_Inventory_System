<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Item;
use App\Models\Role;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database safely and idempotently.
     */
    public function run(): void
    {
        // 1. Seed Roles
        $rolesData = [
            [
                'slug' => Role::SLUG_ADMIN,
                'name' => 'System Administrator',
                'description' => 'Manages master data, system users, and views all reports.',
            ],
            [
                'slug' => Role::SLUG_GM,
                'name' => 'General Manager',
                'description' => 'Approves GM stage of Purchase Requisitions (PRs).',
            ],
            [
                'slug' => Role::SLUG_HOD,
                'name' => 'Head of Department',
                'description' => 'Approves Store Requisitions (SRs) and HOD stage of PRs within assigned department.',
            ],
            [
                'slug' => Role::SLUG_PROCUREMENT,
                'name' => 'Procurement Officer',
                'description' => 'Creates and manages Purchase Orders (POs) from GM-approved PRs.',
            ],
            [
                'slug' => Role::SLUG_STORE_MANAGER,
                'name' => 'Store Manager',
                'description' => 'Approves/posts GRNs (Stock IN), Stock Issues (Stock OUT), and Stock Adjustments.',
            ],
            [
                'slug' => Role::SLUG_STORE_KEEPER,
                'name' => 'Store Keeper',
                'description' => 'Drafts GRNs, Stock Issues, and performs physical stock taking counts.',
            ],
            [
                'slug' => Role::SLUG_DEPARTMENT_STAFF,
                'name' => 'Department Staff',
                'description' => 'Creates and views SRs and PRs for their assigned department.',
            ],
        ];

        $roles = [];
        foreach ($rolesData as $roleData) {
            $roles[$roleData['slug']] = Role::updateOrCreate(
                ['slug' => $roleData['slug']],
                [
                    'name' => $roleData['name'],
                    'description' => $roleData['description'],
                ]
            );
        }

        // 2. Seed Hotel Departments / Cost Centers
        $departmentsData = [
            ['code' => 'DEPT-FO', 'name' => 'Front Office', 'description' => 'Reception, Guest Relations & Concierge'],
            ['code' => 'DEPT-HK', 'name' => 'Housekeeping', 'description' => 'Guest Rooms, Laundry & Public Area Cleaning'],
            ['code' => 'DEPT-FB', 'name' => 'Food & Beverage Service', 'description' => 'Restaurants, Banquet & Bar Operations'],
            ['code' => 'DEPT-KIT', 'name' => 'Main Kitchen', 'description' => 'Culinary & Stewarding Cost Center'],
            ['code' => 'DEPT-ENG', 'name' => 'Engineering & Maintenance', 'description' => 'Facilities, HVAC, Plumbing & Electrical'],
            ['code' => 'DEPT-ADM', 'name' => 'Administration & Finance', 'description' => 'Back-Office, Finance, Purchasing & HR'],
        ];

        $departments = [];
        foreach ($departmentsData as $deptData) {
            $departments[$deptData['code']] = Department::updateOrCreate(
                ['code' => $deptData['code']],
                [
                    'name' => $deptData['name'],
                    'description' => $deptData['description'],
                    'is_active' => true,
                ]
            );
        }

        // 3. Seed Hotel Stores / Warehouses
        $storesData = [
            [
                'code' => 'STR-MAIN',
                'name' => 'Main Central Store',
                'location' => 'Basement B1 - Receiving Dock Area',
                'description' => 'Primary central warehouse for hotel groceries, dry goods, and general supplies.',
            ],
            [
                'code' => 'STR-HK',
                'name' => 'Housekeeping & Amenities Store',
                'location' => 'Basement B1 - Service Corridor',
                'description' => 'Guest room amenities, linens, and housekeeping chemicals.',
            ],
            [
                'code' => 'STR-BEV',
                'name' => 'Beverage & Cellar Store',
                'location' => 'Basement B2 - Climate Controlled Cellar',
                'description' => 'Beverages, soft drinks, mineral water, and cellar stock.',
            ],
        ];

        $stores = [];
        foreach ($storesData as $storeData) {
            $stores[$storeData['code']] = Store::updateOrCreate(
                ['code' => $storeData['code']],
                [
                    'name' => $storeData['name'],
                    'location' => $storeData['location'],
                    'description' => $storeData['description'],
                    'is_active' => true,
                ]
            );
        }

        // 4. Seed Development Users across Roles, Departments, and Stores
        $defaultPassword = Hash::make('password123');

        $usersData = [
            [
                'email' => 'admin@hotel.local',
                'name' => 'System Admin',
                'role_id' => $roles[Role::SLUG_ADMIN]->id,
                'department_id' => $departments['DEPT-ADM']->id,
                'store_id' => null,
            ],
            [
                'email' => 'gm@hotel.local',
                'name' => 'General Manager',
                'role_id' => $roles[Role::SLUG_GM]->id,
                'department_id' => $departments['DEPT-ADM']->id,
                'store_id' => null,
            ],
            [
                'email' => 'hod.hk@hotel.local',
                'name' => 'Housekeeping HOD',
                'role_id' => $roles[Role::SLUG_HOD]->id,
                'department_id' => $departments['DEPT-HK']->id,
                'store_id' => null,
            ],
            [
                'email' => 'staff.hk@hotel.local',
                'name' => 'Housekeeping Supervisor',
                'role_id' => $roles[Role::SLUG_DEPARTMENT_STAFF]->id,
                'department_id' => $departments['DEPT-HK']->id,
                'store_id' => null,
            ],
            [
                'email' => 'hod.kit@hotel.local',
                'name' => 'Executive Chef (Kitchen HOD)',
                'role_id' => $roles[Role::SLUG_HOD]->id,
                'department_id' => $departments['DEPT-KIT']->id,
                'store_id' => null,
            ],
            [
                'email' => 'staff.kit@hotel.local',
                'name' => 'Sous Chef (Kitchen Staff)',
                'role_id' => $roles[Role::SLUG_DEPARTMENT_STAFF]->id,
                'department_id' => $departments['DEPT-KIT']->id,
                'store_id' => null,
            ],
            [
                'email' => 'procurement@hotel.local',
                'name' => 'Procurement Officer',
                'role_id' => $roles[Role::SLUG_PROCUREMENT]->id,
                'department_id' => $departments['DEPT-ADM']->id,
                'store_id' => null,
            ],
            [
                'email' => 'store.manager@hotel.local',
                'name' => 'Main Store Manager',
                'role_id' => $roles[Role::SLUG_STORE_MANAGER]->id,
                'department_id' => $departments['DEPT-ADM']->id,
                'store_id' => $stores['STR-MAIN']->id,
            ],
            [
                'email' => 'store.keeper@hotel.local',
                'name' => 'Main Store Keeper',
                'role_id' => $roles[Role::SLUG_STORE_KEEPER]->id,
                'department_id' => $departments['DEPT-ADM']->id,
                'store_id' => $stores['STR-MAIN']->id,
            ],
        ];

        foreach ($usersData as $userData) {
            User::updateOrCreate(
                ['email' => $userData['email']],
                [
                    'name' => $userData['name'],
                    'password' => $defaultPassword,
                    'role_id' => $userData['role_id'],
                    'department_id' => $userData['department_id'],
                    'store_id' => $userData['store_id'],
                    'is_active' => true,
                    'email_verified_at' => now(),
                ]
            );
        }

        // 5. Seed Suppliers
        $suppliersData = [
            [
                'code' => 'SUP-001',
                'name' => 'Golden Myanmar Hospitality Supplies Co., Ltd.',
                'contact_person' => 'U Kyaw Swar',
                'email' => 'sales@goldenmyanmar-supplies.local',
                'phone' => '+95-9-5100101',
                'address' => 'No. 42, Industrial Zone 1, Yangon',
            ],
            [
                'code' => 'SUP-002',
                'name' => 'Premier Food & Culinary Distributors',
                'contact_person' => 'Daw Su Su',
                'email' => 'orders@premierfood.local',
                'phone' => '+95-9-5100202',
                'address' => 'No. 18, Bayintnaung Wholesale Market, Yangon',
            ],
            [
                'code' => 'SUP-003',
                'name' => 'Apex Beverage & Mineral Water Trading',
                'contact_person' => 'U Min Thu',
                'email' => 'contact@apexbeverage.local',
                'phone' => '+95-9-5100303',
                'address' => 'No. 105, Hlaing Thar Yar Industrial Park, Yangon',
            ],
        ];

        foreach ($suppliersData as $supData) {
            Supplier::updateOrCreate(
                ['code' => $supData['code']],
                array_merge($supData, ['is_active' => true])
            );
        }

        // 6. Seed Items / Products
        $itemsData = [
            [
                'sku' => 'ITM-HK-001',
                'name' => 'Luxury Cotton Bath Towel (White, 700 GSM)',
                'category' => 'Housekeeping Linens',
                'uom' => 'PCS',
                'min_par_level' => 100.000,
                'description' => 'Standard hotel guest room bath towel.',
            ],
            [
                'sku' => 'ITM-HK-002',
                'name' => 'Botanical Guest Shampoo 30ml Bottle',
                'category' => 'Housekeeping Amenities',
                'uom' => 'PCS',
                'min_par_level' => 500.000,
                'description' => 'Single-use guest bathroom shampoo amenity.',
            ],
            [
                'sku' => 'ITM-HK-003',
                'name' => 'Multi-Surface Disinfectant Cleaner 5L',
                'category' => 'Cleaning Supplies',
                'uom' => 'BTL',
                'min_par_level' => 25.000,
                'description' => 'Hospitality grade housekeeping disinfectant.',
            ],
            [
                'sku' => 'ITM-KIT-001',
                'name' => 'Premium Paw San Hmwe Rice (50kg Bag)',
                'category' => 'Dry Groceries',
                'uom' => 'BAG',
                'min_par_level' => 20.000,
                'description' => 'Fragrant rice for main kitchen operations.',
            ],
            [
                'sku' => 'ITM-KIT-002',
                'name' => 'Extra Virgin Olive Oil (5L Tin)',
                'category' => 'Dry Groceries',
                'uom' => 'TIN',
                'min_par_level' => 15.000,
                'description' => 'Culinary olive oil for western & cold kitchen.',
            ],
            [
                'sku' => 'ITM-BEV-001',
                'name' => 'Natural Mineral Water 500ml (Case of 24)',
                'category' => 'Beverages',
                'uom' => 'CASE',
                'min_par_level' => 80.000,
                'description' => 'Complimentary room and banquet mineral water.',
            ],
            [
                'sku' => 'ITM-ENG-001',
                'name' => 'Warm White LED Bulb 9W E27',
                'category' => 'Maintenance Parts',
                'uom' => 'PCS',
                'min_par_level' => 50.000,
                'description' => 'Guest room and corridor replacement LED bulb.',
            ],
        ];

        foreach ($itemsData as $itemData) {
            Item::updateOrCreate(
                ['sku' => $itemData['sku']],
                array_merge($itemData, ['is_active' => true])
            );
        }
    }
}
