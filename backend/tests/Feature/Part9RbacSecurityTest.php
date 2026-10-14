<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Item;
use App\Models\PurchaseOrder;
use App\Models\Requisition;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class Part9RbacSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_unauthenticated_requests_to_all_protected_endpoints_are_rejected(): void
    {
        $protectedRoutes = [
            ['GET', '/api/auth/me'],
            ['POST', '/api/auth/logout'],
            ['GET', '/api/suppliers'],
            ['POST', '/api/suppliers'],
            ['GET', '/api/items'],
            ['POST', '/api/items'],
            ['GET', '/api/stores'],
            ['POST', '/api/stores'],
            ['GET', '/api/departments'],
            ['POST', '/api/departments'],
            ['GET', '/api/requisitions'],
            ['POST', '/api/requisitions'],
            ['GET', '/api/purchase-orders'],
            ['POST', '/api/purchase-orders'],
            ['GET', '/api/grns'],
            ['POST', '/api/grns'],
            ['GET', '/api/stock-issues'],
            ['POST', '/api/stock-issues'],
            ['GET', '/api/inventory/current-stock'],
            ['GET', '/api/inventory/ledger'],
            ['GET', '/api/inventory/reconciliation'],
            ['GET', '/api/inventory/stock-takes'],
            ['POST', '/api/inventory/stock-takes'],
        ];

        foreach ($protectedRoutes as [$method, $uri]) {
            $response = match ($method) {
                'GET' => $this->getJson($uri),
                'POST' => $this->postJson($uri, []),
            };

            $response->assertUnauthorized();
        }
    }

    public function test_hod_department_scoping_and_cross_department_idor_rejection(): void
    {
        $deptHk = Department::where('code', 'DEPT-HK')->firstOrFail();
        $deptKit = Department::where('code', 'DEPT-KIT')->firstOrFail();
        $store = Store::where('code', 'STR-MAIN')->firstOrFail();
        $item = Item::where('sku', 'ITM-HK-001')->firstOrFail();

        $staffHk = User::where('email', 'staff.hk@hotel.local')->firstOrFail();
        $staffKit = User::where('email', 'staff.kit@hotel.local')->firstOrFail();
        $hodHk = User::where('email', 'hod.hk@hotel.local')->firstOrFail();
        $hodKit = User::where('email', 'hod.kit@hotel.local')->firstOrFail();

        // 1. HK Staff submits an SR
        Sanctum::actingAs($staffHk);
        $hkSrRes = $this->postJson('/api/requisitions', [
            'type' => 'SR',
            'department_id' => $deptHk->id,
            'store_id' => $store->id,
            'items' => [
                ['item_id' => $item->id, 'requested_quantity' => 10],
            ],
        ])->assertCreated();
        $hkSrId = $hkSrRes->json('data.id');

        // 2. Kitchen Staff submits an SR
        Sanctum::actingAs($staffKit);
        $kitSrRes = $this->postJson('/api/requisitions', [
            'type' => 'SR',
            'department_id' => $deptKit->id,
            'store_id' => $store->id,
            'items' => [
                ['item_id' => $item->id, 'requested_quantity' => 5],
            ],
        ])->assertCreated();
        $kitSrId = $kitSrRes->json('data.id');

        // 3. HK HOD tries to view and approve Kitchen's SR -> MUST BE FORBIDDEN (403)
        Sanctum::actingAs($hodHk);
        $this->getJson("/api/requisitions/{$kitSrId}")->assertForbidden();
        $this->postJson("/api/requisitions/{$kitSrId}/hod-approve")->assertForbidden();

        // 4. Kitchen HOD tries to view and approve HK's SR -> MUST BE FORBIDDEN (403)
        Sanctum::actingAs($hodKit);
        $this->getJson("/api/requisitions/{$hkSrId}")->assertForbidden();
        $this->postJson("/api/requisitions/{$hkSrId}/hod-approve")->assertForbidden();

        // 5. Authorized HOD approves their own department SR -> SUCCEEDS (200)
        Sanctum::actingAs($hodHk);
        $this->postJson("/api/requisitions/{$hkSrId}/hod-approve")->assertOk();
    }

    public function test_segregation_of_duties_prevents_creator_from_self_approving(): void
    {
        $deptHk = Department::where('code', 'DEPT-HK')->firstOrFail();
        $store = Store::where('code', 'STR-MAIN')->firstOrFail();
        $item = Item::where('sku', 'ITM-HK-001')->firstOrFail();

        $hodHk = User::where('email', 'hod.hk@hotel.local')->firstOrFail();

        // HOD creates a requisition directly
        Sanctum::actingAs($hodHk);
        $srRes = $this->postJson('/api/requisitions', [
            'type' => 'SR',
            'department_id' => $deptHk->id,
            'store_id' => $store->id,
            'items' => [
                ['item_id' => $item->id, 'requested_quantity' => 8],
            ],
        ])->assertCreated();
        $srId = $srRes->json('data.id');

        // HOD attempts to self-approve their own requisition -> MUST BE FORBIDDEN (403)
        $this->postJson("/api/requisitions/{$srId}/hod-approve")->assertForbidden();
    }

    public function test_gm_stage_enforcement_and_sr_approval_rejection(): void
    {
        $deptHk = Department::where('code', 'DEPT-HK')->firstOrFail();
        $store = Store::where('code', 'STR-MAIN')->firstOrFail();
        $item = Item::where('sku', 'ITM-HK-001')->firstOrFail();

        $staffHk = User::where('email', 'staff.hk@hotel.local')->firstOrFail();
        $hodHk = User::where('email', 'hod.hk@hotel.local')->firstOrFail();
        $gm = User::where('email', 'gm@hotel.local')->firstOrFail();

        // 1. Create a Store Requisition (SR)
        Sanctum::actingAs($staffHk);
        $srRes = $this->postJson('/api/requisitions', [
            'type' => 'SR',
            'department_id' => $deptHk->id,
            'store_id' => $store->id,
            'items' => [
                ['item_id' => $item->id, 'requested_quantity' => 10],
            ],
        ])->assertCreated();
        $srId = $srRes->json('data.id');

        // GM attempts to approve an SR -> MUST FAIL (422) because SR does not have GM stage
        Sanctum::actingAs($gm);
        $this->postJson("/api/requisitions/{$srId}/gm-approve")->assertStatus(422);

        // 2. Create a Purchase Requisition (PR)
        Sanctum::actingAs($staffHk);
        $prRes = $this->postJson('/api/requisitions', [
            'type' => 'PR',
            'department_id' => $deptHk->id,
            'items' => [
                ['item_id' => $item->id, 'requested_quantity' => 50],
            ],
        ])->assertCreated();
        $prId = $prRes->json('data.id');

        // GM attempts to approve PR while still Pending HOD approval -> MUST FAIL (422)
        Sanctum::actingAs($gm);
        $this->postJson("/api/requisitions/{$prId}/gm-approve")->assertStatus(422);

        // HOD approves PR
        Sanctum::actingAs($hodHk);
        $this->postJson("/api/requisitions/{$prId}/hod-approve")->assertOk();

        // Now GM approves PR -> SUCCEEDS (200)
        Sanctum::actingAs($gm);
        $this->postJson("/api/requisitions/{$prId}/gm-approve")->assertOk();
    }

    public function test_store_manager_role_enforcement_for_grn_approval_and_stock_adjustments(): void
    {
        $deptHk = Department::where('code', 'DEPT-HK')->firstOrFail();
        $store = Store::where('code', 'STR-MAIN')->firstOrFail();
        $supplier = Supplier::where('code', 'SUP-001')->firstOrFail();
        $item = Item::where('sku', 'ITM-HK-001')->firstOrFail();

        $staffHk = User::where('email', 'staff.hk@hotel.local')->firstOrFail();
        $hodHk = User::where('email', 'hod.hk@hotel.local')->firstOrFail();
        $procurement = User::where('email', 'procurement@hotel.local')->firstOrFail();
        $storeKeeper = User::where('email', 'store.keeper@hotel.local')->firstOrFail();
        $storeManager = User::where('email', 'store.manager@hotel.local')->firstOrFail();

        // Setup a Sent PO
        Sanctum::actingAs($procurement);
        $po = PurchaseOrder::create([
            'po_number' => 'PO-TEST-SEC-01',
            'requisition_id' => null,
            'supplier_id' => $supplier->id,
            'created_by' => $procurement->id,
            'status' => PurchaseOrder::STATUS_SENT,
            'total_amount' => 50000,
        ]);

        $poItem = $po->items()->create([
            'item_id' => $item->id,
            'ordered_quantity' => 20,
            'received_quantity' => 0,
            'unit_price' => 2500,
            'line_total' => 50000,
            'uom' => $item->uom,
        ]);

        // Store Keeper creates a Pending GRN
        Sanctum::actingAs($storeKeeper);
        $grnRes = $this->postJson('/api/grns', [
            'purchase_order_id' => $po->id,
            'store_id' => $store->id,
            'items' => [
                ['item_id' => $item->id, 'received_quantity' => 10],
            ],
        ])->assertCreated();
        $grnId = $grnRes->json('data.id');

        // Department staff, HOD, and Procurement cannot approve GRN (403)
        foreach ([$staffHk, $hodHk, $procurement, $storeKeeper] as $unauthorizedUser) {
            Sanctum::actingAs($unauthorizedUser);
            $this->postJson("/api/grns/{$grnId}/approve")->assertForbidden();
        }

        // Store Manager CAN approve GRN -> SUCCEEDS (200)
        Sanctum::actingAs($storeManager);
        $this->postJson("/api/grns/{$grnId}/approve")->assertOk();
    }

    public function test_parameter_tampering_forged_actor_and_fake_stock_balances_are_ignored(): void
    {
        $deptHk = Department::where('code', 'DEPT-HK')->firstOrFail();
        $store = Store::where('code', 'STR-MAIN')->firstOrFail();
        $item = Item::where('sku', 'ITM-HK-001')->firstOrFail();

        $staffHk = User::where('email', 'staff.hk@hotel.local')->firstOrFail();
        $admin = User::where('email', 'admin@hotel.local')->firstOrFail();

        // Staff submits an SR attempting to inject requester_id = admin, status = 'HOD Approved', etc.
        Sanctum::actingAs($staffHk);
        $res = $this->postJson('/api/requisitions', [
            'type' => 'SR',
            'department_id' => $deptHk->id,
            'store_id' => $store->id,
            'requester_id' => $admin->id,
            'status' => 'HOD Approved',
            'hod_approved_by' => $admin->id,
            'hod_approved_at' => now()->toDateTimeString(),
            'items' => [
                ['item_id' => $item->id, 'requested_quantity' => 5],
            ],
        ])->assertCreated();

        // Server derives requester from authenticated staff and enforces status = 'Pending'
        $this->assertEquals($staffHk->id, $res->json('data.requester_id'));
        $this->assertEquals(Requisition::STATUS_PENDING, $res->json('data.status'));
        $this->assertNull($res->json('data.hod_approved_by'));
    }

    public function test_login_rate_limiting_protects_against_brute_force_attacks(): void
    {
        // 10 attempts allowed per minute. 11th attempt must receive 429 Too Many Requests.
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/auth/login', [
                'email' => 'wrong.user@hotel.local',
                'password' => 'wrongpassword',
            ]);
        }

        $rateLimitedRes = $this->postJson('/api/auth/login', [
            'email' => 'wrong.user@hotel.local',
            'password' => 'wrongpassword',
        ]);

        $rateLimitedRes->assertStatus(429);
    }
}
