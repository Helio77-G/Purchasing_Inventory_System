<?php

namespace Tests\Feature;

use App\Models\CurrentStock;
use App\Models\Department;
use App\Models\Item;
use App\Models\PurchaseOrder;
use App\Models\Requisition;
use App\Models\RequisitionItem;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class Part2MasterDataTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_authentication_login_me_and_logout_endpoints(): void
    {
        // Valid login
        $loginRes = $this->postJson('/api/auth/login', [
            'email' => 'admin@hotel.local',
            'password' => 'password123',
        ]);

        $loginRes->assertOk()
            ->assertJsonStructure([
                'message',
                'data' => [
                    'token',
                    'user' => ['id', 'email', 'role', 'department'],
                ],
            ]);

        $token = $loginRes->json('data.token');

        // Profile check with Bearer token
        $meRes = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/auth/me');

        $meRes->assertOk()
            ->assertJsonPath('data.email', 'admin@hotel.local')
            ->assertJsonPath('data.role.slug', 'admin');

        // Invalid credentials rejected with 422
        $this->postJson('/api/auth/login', [
            'email' => 'admin@hotel.local',
            'password' => 'wrong-password',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['email']);

        // Deactivated user login rejected with 422
        $staff = User::where('email', 'staff.hk@hotel.local')->firstOrFail();
        $staff->update(['is_active' => false]);

        $this->postJson('/api/auth/login', [
            'email' => 'staff.hk@hotel.local',
            'password' => 'password123',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_unauthenticated_and_unauthorized_users_cannot_modify_master_data(): void
    {
        // Unauthenticated requests return 401
        $this->getJson('/api/items')->assertStatus(401);
        $this->postJson('/api/items', [])->assertStatus(401);
        $this->postJson('/api/suppliers', [])->assertStatus(401);
        $this->postJson('/api/stores', [])->assertStatus(401);
        $this->postJson('/api/departments', [])->assertStatus(401);

        // Department staff can read master data (200), but cannot create/update/deactivate/delete (403)
        $staff = User::where('email', 'staff.hk@hotel.local')->firstOrFail();
        Sanctum::actingAs($staff);

        $this->getJson('/api/items')->assertOk();
        $this->getJson('/api/suppliers')->assertOk();
        $this->getJson('/api/stores')->assertOk();
        $this->getJson('/api/departments')->assertOk();

        $supplier = Supplier::firstOrFail();
        $item = Item::firstOrFail();
        $store = Store::firstOrFail();
        $department = Department::firstOrFail();

        $this->postJson('/api/suppliers', ['code' => 'SUP-999', 'name' => 'Test'])->assertStatus(403);
        $this->putJson("/api/suppliers/{$supplier->id}", ['code' => 'SUP-001', 'name' => 'Hack'])->assertStatus(403);
        $this->patchJson("/api/suppliers/{$supplier->id}/deactivate")->assertStatus(403);
        $this->deleteJson("/api/suppliers/{$supplier->id}")->assertStatus(403);

        $this->postJson('/api/items', ['sku' => 'ITM-999', 'name' => 'Test'])->assertStatus(403);
        $this->patchJson("/api/items/{$item->id}/deactivate")->assertStatus(403);

        $this->postJson('/api/stores', ['code' => 'STR-999', 'name' => 'Test'])->assertStatus(403);
        $this->patchJson("/api/stores/{$store->id}/deactivate")->assertStatus(403);

        $this->postJson('/api/departments', ['code' => 'DEPT-999', 'name' => 'Test'])->assertStatus(403);
        $this->patchJson("/api/departments/{$department->id}/deactivate")->assertStatus(403);

        // Procurement officer can manage Suppliers and Items, but NOT Stores or Departments
        $procurement = User::where('email', 'procurement@hotel.local')->firstOrFail();
        Sanctum::actingAs($procurement);

        $this->postJson('/api/stores', ['code' => 'STR-999', 'name' => 'New Store'])->assertStatus(403);
        $this->postJson('/api/departments', ['code' => 'DEPT-999', 'name' => 'New Dept'])->assertStatus(403);
    }

    public function test_supplier_crud_search_filter_pagination_and_deactivation(): void
    {
        $procurement = User::where('email', 'procurement@hotel.local')->firstOrFail();
        Sanctum::actingAs($procurement);

        // 1. Create Supplier
        $createRes = $this->postJson('/api/suppliers', [
            'code' => 'sup-100',
            'name' => 'Yangon Fresh Produce Co.',
            'contact_person' => 'Daw Mya',
            'email' => 'mya@freshproduce.local',
            'phone' => '+95-9-999888777',
            'address' => 'Thiri Mingalar Market, Yangon',
        ]);

        $createRes->assertStatus(201)
            ->assertJsonPath('data.code', 'SUP-100')
            ->assertJsonPath('data.is_active', true);

        $supplierId = $createRes->json('data.id');

        // 2. Duplicate code validation
        $this->postJson('/api/suppliers', [
            'code' => 'SUP-100',
            'name' => 'Duplicate Supplier',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['code']);

        // 3. View Detail
        $this->getJson("/api/suppliers/{$supplierId}")
            ->assertOk()
            ->assertJsonPath('data.name', 'Yangon Fresh Produce Co.');

        // 4. Update Supplier (allowing its own code)
        $this->putJson("/api/suppliers/{$supplierId}", [
            'code' => 'SUP-100',
            'name' => 'Yangon Fresh Produce & Dairy Co.',
            'contact_person' => 'Daw Mya Mya',
            'email' => 'mya@freshproduce.local',
        ])->assertOk()
            ->assertJsonPath('data.name', 'Yangon Fresh Produce & Dairy Co.');

        // 5. Deactivate Supplier
        $this->patchJson("/api/suppliers/{$supplierId}/deactivate")
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        // 6. Search & Filter by is_active
        $inactiveList = $this->getJson('/api/suppliers?is_active=0&search=Dairy')
            ->assertOk();
        $this->assertCount(1, $inactiveList->json('data'));
        $this->assertEquals('SUP-100', $inactiveList->json('data.0.code'));
    }

    public function test_item_crud_validation_uom_par_level_filter_and_deactivation(): void
    {
        $storeManager = User::where('email', 'store.manager@hotel.local')->firstOrFail();
        Sanctum::actingAs($storeManager);

        // 1. Reject invalid UoM and negative min_par_level
        $this->postJson('/api/items', [
            'sku' => 'ITM-TEST-01',
            'name' => 'Invalid Item',
            'category' => 'Beverages',
            'uom' => 'INVALID_UOM',
            'min_par_level' => -5,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['uom', 'min_par_level']);

        // 2. Reject duplicate SKU
        $this->postJson('/api/items', [
            'sku' => 'ITM-HK-001',
            'name' => 'Duplicate Towel',
            'category' => 'Housekeeping Linens',
            'uom' => 'PCS',
            'min_par_level' => 10,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['sku']);

        // 3. Create valid Item
        $createRes = $this->postJson('/api/items', [
            'sku' => 'itm-bev-099',
            'name' => 'Sparkling Mineral Water 750ml',
            'category' => 'Beverages',
            'uom' => 'btl',
            'min_par_level' => 48.5,
            'description' => 'Glass bottle sparkling water for fine dining',
        ]);

        $createRes->assertStatus(201)
            ->assertJsonPath('data.sku', 'ITM-BEV-099')
            ->assertJsonPath('data.uom', 'BTL')
            ->assertJsonPath('data.min_par_level', '48.500');

        $itemId = $createRes->json('data.id');

        // 4. Update Item
        $this->putJson("/api/items/{$itemId}", [
            'sku' => 'ITM-BEV-099',
            'name' => 'Sparkling Mineral Water 750ml (Imported)',
            'category' => 'Beverages',
            'uom' => 'CASE',
            'min_par_level' => 60,
        ])->assertOk()
            ->assertJsonPath('data.uom', 'CASE')
            ->assertJsonPath('data.min_par_level', '60.000');

        // 5. Filter by category & paginate
        $listRes = $this->getJson('/api/items?category=Beverages&per_page=5&sort_by=sku&sort_dir=desc')
            ->assertOk();
        $this->assertGreaterThanOrEqual(2, count($listRes->json('data')));
        $this->assertEquals('ITM-BEV-099', $listRes->json('data.0.sku'));

        // 6. Deactivate Item
        $this->patchJson("/api/items/{$itemId}/deactivate")
            ->assertOk()
            ->assertJsonPath('data.is_active', false);
    }

    public function test_store_and_department_crud_and_deactivation(): void
    {
        $admin = User::where('email', 'admin@hotel.local')->firstOrFail();
        Sanctum::actingAs($admin);

        // 1. Create Store
        $storeRes = $this->postJson('/api/stores', [
            'code' => 'str-eng',
            'name' => 'Engineering Spare Parts Store',
            'location' => 'Basement B2 - Workshop',
            'description' => 'Electrical and HVAC spare parts',
        ])->assertStatus(201)
            ->assertJsonPath('data.code', 'STR-ENG');

        $storeId = $storeRes->json('data.id');

        // Duplicate store code rejected
        $this->postJson('/api/stores', [
            'code' => 'STR-ENG',
            'name' => 'Duplicate Store',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['code']);

        // Update & Deactivate Store
        $this->putJson("/api/stores/{$storeId}", [
            'code' => 'STR-ENG',
            'name' => 'Engineering & Technical Store',
            'location' => 'Basement B2',
        ])->assertOk()
            ->assertJsonPath('data.name', 'Engineering & Technical Store');

        $this->patchJson("/api/stores/{$storeId}/deactivate")
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        // 2. Create Department
        $deptRes = $this->postJson('/api/departments', [
            'code' => 'dept-spa',
            'name' => 'Spa & Wellness Center',
            'description' => 'Hotel Spa, Gym & Pool operations',
        ])->assertStatus(201)
            ->assertJsonPath('data.code', 'DEPT-SPA');

        $deptId = $deptRes->json('data.id');

        // Update & Deactivate Department
        $this->putJson("/api/departments/{$deptId}", [
            'code' => 'DEPT-SPA',
            'name' => 'Luxury Spa & Wellness',
        ])->assertOk()
            ->assertJsonPath('data.name', 'Luxury Spa & Wellness');

        $this->patchJson("/api/departments/{$deptId}/deactivate")
            ->assertOk()
            ->assertJsonPath('data.is_active', false);
    }

    public function test_referenced_records_cannot_be_hard_deleted_while_unreferenced_records_can(): void
    {
        $admin = User::where('email', 'admin@hotel.local')->firstOrFail();
        Sanctum::actingAs($admin);

        $department = Department::where('code', 'DEPT-HK')->firstOrFail();
        $store = Store::where('code', 'STR-MAIN')->firstOrFail();
        $supplier = Supplier::where('code', 'SUP-001')->firstOrFail();
        $item = Item::where('sku', 'ITM-HK-001')->firstOrFail();

        // Create historical references
        $sr = Requisition::create([
            'requisition_number' => 'SR-202610-0010',
            'type' => Requisition::TYPE_SR,
            'department_id' => $department->id,
            'store_id' => $store->id,
            'requester_id' => $admin->id,
            'status' => Requisition::STATUS_PENDING,
        ]);

        RequisitionItem::create([
            'requisition_id' => $sr->id,
            'item_id' => $item->id,
            'requested_quantity' => 5,
            'issued_quantity' => 0,
            'uom' => $item->uom,
        ]);

        PurchaseOrder::create([
            'po_number' => 'PO-202610-0010',
            'requisition_id' => null,
            'supplier_id' => $supplier->id,
            'created_by' => $admin->id,
            'status' => PurchaseOrder::STATUS_DRAFT,
            'total_amount' => 1000,
        ]);

        CurrentStock::create([
            'item_id' => $item->id,
            'store_id' => $store->id,
            'quantity' => 25,
        ]);

        // Deleting referenced records returns 422 with clear error message
        $this->deleteJson("/api/items/{$item->id}")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['item']);

        $this->deleteJson("/api/suppliers/{$supplier->id}")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['supplier']);

        $this->deleteJson("/api/stores/{$store->id}")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['store']);

        $this->deleteJson("/api/departments/{$department->id}")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['department']);

        // Unreferenced supplier can be deleted cleanly
        $unreferencedSupplier = Supplier::create([
            'code' => 'SUP-TEMP',
            'name' => 'Temporary Unused Supplier',
            'is_active' => true,
        ]);

        $this->deleteJson("/api/suppliers/{$unreferencedSupplier->id}")
            ->assertOk();
        $this->assertDatabaseMissing('suppliers', ['id' => $unreferencedSupplier->id]);
    }

    public function test_sorting_accepts_only_allow_listed_columns_and_rejects_arbitrary_queries(): void
    {
        $admin = User::where('email', 'admin@hotel.local')->firstOrFail();
        Sanctum::actingAs($admin);

        // SQL injection / arbitrary column names in sort_by or sort_dir must be rejected with 422
        $this->getJson('/api/items?sort_by=password_hash')->assertStatus(422);
        $this->getJson('/api/items?sort_by=name;DROP TABLE items;--')->assertStatus(422);
        $this->getJson('/api/items?sort_by=name&sort_dir= sideways')->assertStatus(422);

        $this->getJson('/api/suppliers?sort_by=secret_column')->assertStatus(422);
        $this->getJson('/api/stores?sort_by=1=1')->assertStatus(422);
        $this->getJson('/api/departments?sort_by=unknown_col')->assertStatus(422);

        // Allow-listed columns succeed
        $this->getJson('/api/items?sort_by=min_par_level&sort_dir=asc')->assertOk();
        $this->getJson('/api/suppliers?sort_by=name&sort_dir=desc')->assertOk();
        $this->getJson('/api/stores?sort_by=code&sort_dir=asc')->assertOk();
        $this->getJson('/api/departments?sort_by=name&sort_dir=asc')->assertOk();
    }
}
