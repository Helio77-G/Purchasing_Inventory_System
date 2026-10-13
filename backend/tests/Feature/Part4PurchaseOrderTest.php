<?php

namespace Tests\Feature;

use App\Models\CurrentStock;
use App\Models\Department;
use App\Models\Item;
use App\Models\PurchaseOrder;
use App\Models\Requisition;
use App\Models\RequisitionItem;
use App\Models\StockLedger;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class Part4PurchaseOrderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function createPrWithStatus(string $status, string $reqNumber = 'PR-202610-0100'): Requisition
    {
        $kitDept = Department::where('code', 'DEPT-KIT')->firstOrFail();
        $staff = User::where('email', 'staff.kit@hotel.local')->firstOrFail();
        $hod = User::where('email', 'hod.kit@hotel.local')->firstOrFail();
        $gm = User::where('email', 'gm@hotel.local')->firstOrFail();
        $rice = Item::where('sku', 'ITM-KIT-001')->firstOrFail();
        $oil = Item::where('sku', 'ITM-KIT-002')->firstOrFail();

        $pr = Requisition::create([
            'requisition_number' => $reqNumber,
            'type' => Requisition::TYPE_PR,
            'department_id' => $kitDept->id,
            'requester_id' => $staff->id,
            'status' => $status,
            'hod_approved_by' => in_array($status, [Requisition::STATUS_HOD_APPROVED, Requisition::STATUS_GM_APPROVED], true) ? $hod->id : null,
            'hod_approved_at' => in_array($status, [Requisition::STATUS_HOD_APPROVED, Requisition::STATUS_GM_APPROVED], true) ? now()->subHours(2) : null,
            'gm_approved_by' => $status === Requisition::STATUS_GM_APPROVED ? $gm->id : null,
            'gm_approved_at' => $status === Requisition::STATUS_GM_APPROVED ? now()->subHour() : null,
        ]);

        RequisitionItem::create([
            'requisition_id' => $pr->id,
            'item_id' => $rice->id,
            'requested_quantity' => 20.000,
            'issued_quantity' => 0,
            'uom' => $rice->uom,
        ]);

        RequisitionItem::create([
            'requisition_id' => $pr->id,
            'item_id' => $oil->id,
            'requested_quantity' => 10.500,
            'issued_quantity' => 0,
            'uom' => $oil->uom,
        ]);

        return $pr->fresh('items');
    }

    public function test_only_fully_gm_approved_pr_can_generate_po_and_duplicate_pos_are_blocked(): void
    {
        $procurement = User::where('email', 'procurement@hotel.local')->firstOrFail();
        $supplier = Supplier::where('code', 'SUP-002')->firstOrFail();
        $rice = Item::where('sku', 'ITM-KIT-001')->firstOrFail();
        $mainStore = Store::where('code', 'STR-MAIN')->firstOrFail();
        $hkDept = Department::where('code', 'DEPT-HK')->firstOrFail();

        Sanctum::actingAs($procurement);

        // 1. Pending PR cannot create a PO
        $pendingPr = $this->createPrWithStatus(Requisition::STATUS_PENDING, 'PR-202610-0101');
        $this->postJson('/api/purchase-orders', [
            'requisition_id' => $pendingPr->id,
            'supplier_id' => $supplier->id,
            'items' => [
                ['item_id' => $rice->id, 'ordered_quantity' => 20, 'unit_price' => 45000],
            ],
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['requisition_id']);

        // 2. HOD-Approved (but not GM-Approved) PR cannot create a PO
        $hodPr = $this->createPrWithStatus(Requisition::STATUS_HOD_APPROVED, 'PR-202610-0102');
        $this->postJson('/api/purchase-orders', [
            'requisition_id' => $hodPr->id,
            'supplier_id' => $supplier->id,
            'items' => [
                ['item_id' => $rice->id, 'ordered_quantity' => 20, 'unit_price' => 45000],
            ],
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['requisition_id']);

        // 3. Store Requisition (SR) cannot create a PO
        $sr = Requisition::create([
            'requisition_number' => 'SR-202610-0103',
            'type' => Requisition::TYPE_SR,
            'department_id' => $hkDept->id,
            'store_id' => $mainStore->id,
            'requester_id' => $procurement->id,
            'status' => Requisition::STATUS_HOD_APPROVED,
        ]);
        $this->postJson('/api/purchase-orders', [
            'requisition_id' => $sr->id,
            'supplier_id' => $supplier->id,
            'items' => [
                ['item_id' => $rice->id, 'ordered_quantity' => 5, 'unit_price' => 45000],
            ],
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['requisition_id']);

        // 4. GM-Approved PR succeeds and transitions PR to PO Generated
        $gmPr = $this->createPrWithStatus(Requisition::STATUS_GM_APPROVED, 'PR-202610-0104');
        $poRes = $this->postJson('/api/purchase-orders', [
            'requisition_id' => $gmPr->id,
            'supplier_id' => $supplier->id,
            'items' => [
                ['item_id' => $rice->id, 'ordered_quantity' => 20, 'unit_price' => 45000],
            ],
        ])->assertStatus(201)
            ->assertJsonPath('data.status', PurchaseOrder::STATUS_DRAFT);

        $this->assertEquals(Requisition::STATUS_PO_GENERATED, $gmPr->fresh()->status);

        // 5. Duplicate PO request against the same PR is rejected with 422
        $this->postJson('/api/purchase-orders', [
            'requisition_id' => $gmPr->id,
            'supplier_id' => $supplier->id,
            'items' => [
                ['item_id' => $rice->id, 'ordered_quantity' => 20, 'unit_price' => 45000],
            ],
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['requisition_id']);
    }

    public function test_supplier_item_quantity_and_price_validation_and_server_total_calculation(): void
    {
        $procurement = User::where('email', 'procurement@hotel.local')->firstOrFail();
        $supplier = Supplier::where('code', 'SUP-002')->firstOrFail();
        $rice = Item::where('sku', 'ITM-KIT-001')->firstOrFail();
        $oil = Item::where('sku', 'ITM-KIT-002')->firstOrFail();
        $towel = Item::where('sku', 'ITM-HK-001')->firstOrFail();

        Sanctum::actingAs($procurement);
        $gmPr = $this->createPrWithStatus(Requisition::STATUS_GM_APPROVED, 'PR-202610-0200');

        // 1. Non-positive ordered_quantity or unit_price rejected
        $this->postJson('/api/purchase-orders', [
            'requisition_id' => $gmPr->id,
            'supplier_id' => $supplier->id,
            'items' => [
                ['item_id' => $rice->id, 'ordered_quantity' => 0, 'unit_price' => 45000],
            ],
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['items.0.ordered_quantity']);

        $this->postJson('/api/purchase-orders', [
            'requisition_id' => $gmPr->id,
            'supplier_id' => $supplier->id,
            'items' => [
                ['item_id' => $rice->id, 'ordered_quantity' => 10, 'unit_price' => -100],
            ],
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['items.0.unit_price']);

        // 2. Item not present on the originating PR is rejected
        $this->postJson('/api/purchase-orders', [
            'requisition_id' => $gmPr->id,
            'supplier_id' => $supplier->id,
            'items' => [
                ['item_id' => $towel->id, 'ordered_quantity' => 10, 'unit_price' => 15000],
            ],
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['items.0.item_id']);

        // 3. Inactive supplier is rejected
        $inactiveSupplier = Supplier::create([
            'code' => 'SUP-INACT',
            'name' => 'Inactive Supplier',
            'is_active' => false,
        ]);
        $this->postJson('/api/purchase-orders', [
            'requisition_id' => $gmPr->id,
            'supplier_id' => $inactiveSupplier->id,
            'items' => [
                ['item_id' => $rice->id, 'ordered_quantity' => 10, 'unit_price' => 45000],
            ],
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['supplier_id']);

        // 4. Server calculates exact line totals and grand total, ignoring fake client totals
        // Line 1: 20.000 * 48500.50 = 970010.0000
        // Line 2: 10.500 * 32000.00 = 336000.0000
        // Expected Grand Total = 1306010.0000
        $res = $this->postJson('/api/purchase-orders', [
            'requisition_id' => $gmPr->id,
            'supplier_id' => $supplier->id,
            'total_amount' => 1.0000, // Fake client grand total must be ignored
            'items' => [
                [
                    'item_id' => $rice->id,
                    'ordered_quantity' => 20,
                    'unit_price' => 48500.50,
                    'line_total' => 5.00, // Fake client line total must be ignored
                ],
                [
                    'item_id' => $oil->id,
                    'ordered_quantity' => 10.5,
                    'unit_price' => 32000,
                    'line_total' => 5.00,
                ],
            ],
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('data.total_amount', '1306010.0000')
            ->assertJsonPath('data.items.0.line_total', '970010.0000')
            ->assertJsonPath('data.items.0.received_quantity', '0.000')
            ->assertJsonPath('data.items.1.line_total', '336000.0000');
    }

    public function test_po_status_transitions_edit_restrictions_and_zero_stock_impact(): void
    {
        $procurement = User::where('email', 'procurement@hotel.local')->firstOrFail();
        $supplier = Supplier::where('code', 'SUP-002')->firstOrFail();
        $altSupplier = Supplier::where('code', 'SUP-001')->firstOrFail();
        $rice = Item::where('sku', 'ITM-KIT-001')->firstOrFail();

        Sanctum::actingAs($procurement);
        $gmPr = $this->createPrWithStatus(Requisition::STATUS_GM_APPROVED, 'PR-202610-0300');

        $createRes = $this->postJson('/api/purchase-orders', [
            'requisition_id' => $gmPr->id,
            'supplier_id' => $supplier->id,
            'items' => [
                ['item_id' => $rice->id, 'ordered_quantity' => 20, 'unit_price' => 45000],
            ],
        ])->assertStatus(201);

        $poId = $createRes->json('data.id');

        // 1. Draft PO can be edited and recalculates totals
        $this->putJson("/api/purchase-orders/{$poId}", [
            'supplier_id' => $altSupplier->id,
            'notes' => 'Negotiated bulk discount',
            'items' => [
                ['item_id' => $rice->id, 'ordered_quantity' => 20, 'unit_price' => 42000],
            ],
        ])->assertOk()
            ->assertJsonPath('data.supplier_id', $altSupplier->id)
            ->assertJsonPath('data.total_amount', '840000.0000');

        // 2. Client cannot manually jump PO status to Partial Received or Fully Received
        $this->patchJson("/api/purchase-orders/{$poId}/status", [
            'status' => PurchaseOrder::STATUS_PARTIAL_RECEIVED,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['status']);

        $this->patchJson("/api/purchase-orders/{$poId}/status", [
            'status' => PurchaseOrder::STATUS_FULLY_RECEIVED,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['status']);

        // 3. Send PO transitions status from Draft -> Sent
        $sendRes = $this->postJson("/api/purchase-orders/{$poId}/send")
            ->assertOk()
            ->assertJsonPath('data.status', PurchaseOrder::STATUS_SENT)
            ->assertJsonPath('data.sent_by', $procurement->id);
        $this->assertNotNull($sendRes->json('data.sent_at'));

        // 4. Sending an already Sent PO is rejected with 422
        $this->postJson("/api/purchase-orders/{$poId}/send")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);

        // 5. Editing a Sent PO is rejected with 422
        $this->putJson("/api/purchase-orders/{$poId}", [
            'supplier_id' => $supplier->id,
            'items' => [
                ['item_id' => $rice->id, 'ordered_quantity' => 50, 'unit_price' => 10000],
            ],
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['status']);

        // 6. Creating and sending a PO does NOT change current_stocks or create stock_ledger entries
        $this->assertEquals(0, CurrentStock::count());
        $this->assertEquals(0, StockLedger::count());
    }

    public function test_unauthorized_users_cannot_create_update_or_send_purchase_orders(): void
    {
        $gmPr = $this->createPrWithStatus(Requisition::STATUS_GM_APPROVED, 'PR-202610-0400');
        $supplier = Supplier::where('code', 'SUP-002')->firstOrFail();
        $rice = Item::where('sku', 'ITM-KIT-001')->firstOrFail();

        // Unauthenticated -> 401
        $this->postJson('/api/purchase-orders', [])->assertStatus(401);

        // Department staff, HOD, Store Keeper, Store Manager, and GM cannot create or send POs -> 403
        $unauthorizedEmails = [
            'staff.hk@hotel.local',
            'hod.kit@hotel.local',
            'store.keeper@hotel.local',
            'store.manager@hotel.local',
            'gm@hotel.local',
        ];

        foreach ($unauthorizedEmails as $email) {
            $user = User::where('email', $email)->firstOrFail();
            Sanctum::actingAs($user);

            $this->postJson('/api/purchase-orders', [
                'requisition_id' => $gmPr->id,
                'supplier_id' => $supplier->id,
                'items' => [
                    ['item_id' => $rice->id, 'ordered_quantity' => 20, 'unit_price' => 45000],
                ],
            ])->assertStatus(403, "User [{$email}] must not be permitted to create POs.");
        }
    }
}
