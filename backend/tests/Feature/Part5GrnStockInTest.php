<?php

namespace Tests\Feature;

use App\Models\CurrentStock;
use App\Models\Department;
use App\Models\GoodsReceivingNote;
use App\Models\Item;
use App\Models\PoItem;
use App\Models\PurchaseOrder;
use App\Models\Requisition;
use App\Models\RequisitionItem;
use App\Models\Role;
use App\Models\StockLedger;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class Part5GrnStockInTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function createSentPo(): PurchaseOrder
    {
        $kitDept = Department::where('code', 'DEPT-KIT')->firstOrFail();
        $staff = User::where('email', 'staff.kit@hotel.local')->firstOrFail();
        $hod = User::where('email', 'hod.kit@hotel.local')->firstOrFail();
        $gm = User::where('email', 'gm@hotel.local')->firstOrFail();
        $procurement = User::where('email', 'procurement@hotel.local')->firstOrFail();
        $supplier = Supplier::where('code', 'SUP-002')->firstOrFail();
        $rice = Item::where('sku', 'ITM-KIT-001')->firstOrFail();
        $oil = Item::where('sku', 'ITM-KIT-002')->firstOrFail();

        $pr = Requisition::create([
            'requisition_number' => 'PR-202610-0500',
            'type' => Requisition::TYPE_PR,
            'department_id' => $kitDept->id,
            'requester_id' => $staff->id,
            'status' => Requisition::STATUS_PO_GENERATED,
            'hod_approved_by' => $hod->id,
            'hod_approved_at' => now()->subHours(3),
            'gm_approved_by' => $gm->id,
            'gm_approved_at' => now()->subHours(2),
        ]);

        $prRice = RequisitionItem::create([
            'requisition_id' => $pr->id,
            'item_id' => $rice->id,
            'requested_quantity' => 50.000,
            'issued_quantity' => 0,
            'uom' => $rice->uom,
        ]);

        $prOil = RequisitionItem::create([
            'requisition_id' => $pr->id,
            'item_id' => $oil->id,
            'requested_quantity' => 20.000,
            'issued_quantity' => 0,
            'uom' => $oil->uom,
        ]);

        $po = PurchaseOrder::create([
            'po_number' => 'PO-202610-0500',
            'requisition_id' => $pr->id,
            'supplier_id' => $supplier->id,
            'created_by' => $procurement->id,
            'sent_by' => $procurement->id,
            'sent_at' => now()->subHour(),
            'status' => PurchaseOrder::STATUS_SENT,
            'total_amount' => 3100000.0000,
        ]);

        PoItem::create([
            'purchase_order_id' => $po->id,
            'requisition_item_id' => $prRice->id,
            'item_id' => $rice->id,
            'ordered_quantity' => 50.000,
            'received_quantity' => 0.000,
            'unit_price' => 50000.0000,
            'line_total' => 2500000.0000,
            'uom' => $rice->uom,
        ]);

        PoItem::create([
            'purchase_order_id' => $po->id,
            'requisition_item_id' => $prOil->id,
            'item_id' => $oil->id,
            'ordered_quantity' => 20.000,
            'received_quantity' => 0.000,
            'unit_price' => 30000.0000,
            'line_total' => 600000.0000,
            'uom' => $oil->uom,
        ]);

        return $po->fresh('items.item');
    }

    public function test_submitting_pending_grn_does_not_modify_stock_and_approving_partial_then_final_grn_updates_stock_and_po_status(): void
    {
        $po = $this->createSentPo();
        $mainStore = Store::where('code', 'STR-MAIN')->firstOrFail();
        $bevStore = Store::where('code', 'STR-BEV')->firstOrFail();
        $storeKeeper = User::where('email', 'store.keeper@hotel.local')->firstOrFail();
        $storeManager = User::where('email', 'store.manager@hotel.local')->firstOrFail();

        $ricePoItem = $po->items[0];
        $oilPoItem = $po->items[1];

        // Seed existing stock in another store (STR-BEV) to verify store isolation
        CurrentStock::create([
            'item_id' => $ricePoItem->item_id,
            'store_id' => $bevStore->id,
            'quantity' => 12.000,
        ]);

        // 1. Store Keeper records Partial GRN #1 (Pending)
        Sanctum::actingAs($storeKeeper);
        $grn1Res = $this->postJson('/api/grns', [
            'purchase_order_id' => $po->id,
            'store_id' => $mainStore->id,
            'delivery_note_number' => 'DN-PART-01',
            'notes' => 'First partial delivery',
            'items' => [
                ['po_item_id' => $ricePoItem->id, 'received_quantity' => 30.000],
                ['po_item_id' => $oilPoItem->id, 'received_quantity' => 8.500],
            ],
        ]);

        $grn1Res->assertStatus(201)
            ->assertJsonPath('data.status', GoodsReceivingNote::STATUS_PENDING);

        $grn1Id = $grn1Res->json('data.id');

        // Confirm Pending GRN did NOT change CurrentStock in STR-MAIN or create StockLedger entries
        $this->assertNull(
            CurrentStock::where('item_id', $ricePoItem->item_id)->where('store_id', $mainStore->id)->first()
        );
        $this->assertEquals(0, StockLedger::count());
        $this->assertEquals(PurchaseOrder::STATUS_SENT, $po->fresh()->status);

        // 2. Store Manager approves GRN #1 -> Atomic Stock IN + PO becomes Partial Received
        Sanctum::actingAs($storeManager);
        $approve1Res = $this->postJson("/api/grns/{$grn1Id}/approve");

        $approve1Res->assertOk()
            ->assertJsonPath('data.status', GoodsReceivingNote::STATUS_APPROVED)
            ->assertJsonPath('data.approved_by', $storeManager->id);

        // Verify PO status is now Partial Received and received quantities updated
        $po->refresh();
        $this->assertEquals(PurchaseOrder::STATUS_PARTIAL_RECEIVED, $po->status);
        $this->assertEquals('30.000', $ricePoItem->fresh()->received_quantity);
        $this->assertEquals('8.500', $oilPoItem->fresh()->received_quantity);

        // Verify CurrentStock in STR-MAIN increased, while STR-BEV remained 12.000
        $this->assertEquals(
            '30.000',
            CurrentStock::where('item_id', $ricePoItem->item_id)->where('store_id', $mainStore->id)->value('quantity')
        );
        $this->assertEquals(
            '8.500',
            CurrentStock::where('item_id', $oilPoItem->item_id)->where('store_id', $mainStore->id)->value('quantity')
        );
        $this->assertEquals(
            '12.000',
            CurrentStock::where('item_id', $ricePoItem->item_id)->where('store_id', $bevStore->id)->value('quantity')
        );

        // Verify exactly 2 StockLedger IN entries exist with source GRN and actor
        $this->assertEquals(2, StockLedger::count());
        $riceLedger = StockLedger::where('item_id', $ricePoItem->item_id)->firstOrFail();
        $this->assertEquals(StockLedger::TYPE_IN, $riceLedger->transaction_type);
        $this->assertEquals('30.000', $riceLedger->quantity);
        $this->assertEquals('30.000', $riceLedger->balance_after);
        $this->assertEquals(StockLedger::REF_GRN, $riceLedger->reference_type);
        $this->assertEquals($grn1Id, $riceLedger->reference_id);
        $this->assertEquals($storeManager->id, $riceLedger->created_by);

        // 3. Store Keeper records Final GRN #2 for remaining balance (20.000 Rice, 11.500 Oil)
        Sanctum::actingAs($storeKeeper);
        $grn2Res = $this->postJson('/api/grns', [
            'purchase_order_id' => $po->id,
            'store_id' => $mainStore->id,
            'delivery_note_number' => 'DN-FINAL-02',
            'items' => [
                ['po_item_id' => $ricePoItem->id, 'received_quantity' => 20.000],
                ['po_item_id' => $oilPoItem->id, 'received_quantity' => 11.500],
            ],
        ])->assertStatus(201);

        $grn2Id = $grn2Res->json('data.id');

        // 4. Store Manager approves Final GRN #2 -> PO becomes Fully Received and stock totals match
        Sanctum::actingAs($storeManager);
        $this->postJson("/api/grns/{$grn2Id}/approve")->assertOk();

        $this->assertEquals(PurchaseOrder::STATUS_FULLY_RECEIVED, $po->fresh()->status);
        $this->assertEquals('50.000', $ricePoItem->fresh()->received_quantity);
        $this->assertEquals('20.000', $oilPoItem->fresh()->received_quantity);

        $this->assertEquals(
            '50.000',
            CurrentStock::where('item_id', $ricePoItem->item_id)->where('store_id', $mainStore->id)->value('quantity')
        );
        $this->assertEquals(
            '20.000',
            CurrentStock::where('item_id', $oilPoItem->item_id)->where('store_id', $mainStore->id)->value('quantity')
        );
        $this->assertEquals(4, StockLedger::count());
    }

    public function test_over_receipt_invalid_quantities_and_double_posting_are_rejected(): void
    {
        $po = $this->createSentPo();
        $mainStore = Store::where('code', 'STR-MAIN')->firstOrFail();
        $storeManager = User::where('email', 'store.manager@hotel.local')->firstOrFail();
        $ricePoItem = $po->items[0]; // Ordered: 50.000

        Sanctum::actingAs($storeManager);

        // 1. Zero or negative quantity rejected
        $this->postJson('/api/grns', [
            'purchase_order_id' => $po->id,
            'store_id' => $mainStore->id,
            'items' => [
                ['po_item_id' => $ricePoItem->id, 'received_quantity' => 0],
            ],
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['items.0.received_quantity']);

        // 2. Over-receipt (> 50.000) rejected on creation
        $this->postJson('/api/grns', [
            'purchase_order_id' => $po->id,
            'store_id' => $mainStore->id,
            'items' => [
                ['po_item_id' => $ricePoItem->id, 'received_quantity' => 50.001],
            ],
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['items.0.received_quantity']);

        // 3. Create two competing Pending GRNs of 35.000 each (each <= 50.000 at creation time)
        $grnA = $this->postJson('/api/grns', [
            'purchase_order_id' => $po->id,
            'store_id' => $mainStore->id,
            'items' => [
                ['po_item_id' => $ricePoItem->id, 'received_quantity' => 35.000],
            ],
        ])->assertStatus(201)->json('data.id');

        $grnB = $this->postJson('/api/grns', [
            'purchase_order_id' => $po->id,
            'store_id' => $mainStore->id,
            'items' => [
                ['po_item_id' => $ricePoItem->id, 'received_quantity' => 35.000],
            ],
        ])->assertStatus(201)->json('data.id');

        // Approve GRN A -> succeeds (remaining PO balance is now 15.000)
        $this->postJson("/api/grns/{$grnA}/approve")->assertOk();

        // 4. Repeated/duplicate click on GRN A is rejected with 422 and does NOT double stock
        $this->postJson("/api/grns/{$grnA}/approve")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);

        // 5. Approving competing GRN B (35.000 > remaining 15.000) is rejected with 422
        $this->postJson("/api/grns/{$grnB}/approve")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['items']);

        // Confirm stock balance is still exactly 35.000 and only 1 ledger entry exists
        $this->assertEquals(
            '35.000',
            CurrentStock::where('item_id', $ricePoItem->item_id)->where('store_id', $mainStore->id)->value('quantity')
        );
        $this->assertEquals(1, StockLedger::count());
    }

    public function test_unauthorized_users_and_non_store_managers_cannot_approve_grn(): void
    {
        $po = $this->createSentPo();
        $mainStore = Store::where('code', 'STR-MAIN')->firstOrFail();
        $bevStore = Store::where('code', 'STR-BEV')->firstOrFail();
        $storeKeeper = User::where('email', 'store.keeper@hotel.local')->firstOrFail();
        $ricePoItem = $po->items[0];

        Sanctum::actingAs($storeKeeper);
        $grnId = $this->postJson('/api/grns', [
            'purchase_order_id' => $po->id,
            'store_id' => $mainStore->id,
            'items' => [
                ['po_item_id' => $ricePoItem->id, 'received_quantity' => 10.000],
            ],
        ])->assertStatus(201)->json('data.id');

        // Non-Store Managers (including store_keeper, procurement_officer, hod, gm, staff, admin) cannot approve GRNs
        $nonStoreManagerEmails = [
            'store.keeper@hotel.local',
            'procurement@hotel.local',
            'hod.kit@hotel.local',
            'gm@hotel.local',
            'staff.hk@hotel.local',
            'admin@hotel.local',
        ];

        foreach ($nonStoreManagerEmails as $email) {
            $user = User::where('email', $email)->firstOrFail();
            Sanctum::actingAs($user);

            $this->postJson("/api/grns/{$grnId}/approve")
                ->assertStatus(403, "User [{$email}] must not be allowed to approve GRN.");
        }

        // A Store Manager assigned to a different store (STR-BEV) cannot approve a GRN for STR-MAIN
        $smRole = Role::where('slug', Role::SLUG_STORE_MANAGER)->firstOrFail();
        $otherStoreManager = User::factory()->create([
            'role_id' => $smRole->id,
            'store_id' => $bevStore->id,
            'is_active' => true,
        ]);

        Sanctum::actingAs($otherStoreManager);
        $this->postJson("/api/grns/{$grnId}/approve")->assertStatus(403);
    }

    public function test_simulated_database_failure_during_grn_approval_rolls_back_all_changes_atomically(): void
    {
        $po = $this->createSentPo();
        $mainStore = Store::where('code', 'STR-MAIN')->firstOrFail();
        $storeManager = User::where('email', 'store.manager@hotel.local')->firstOrFail();
        $ricePoItem = $po->items[0];
        $oilPoItem = $po->items[1];

        Sanctum::actingAs($storeManager);
        $grnId = $this->postJson('/api/grns', [
            'purchase_order_id' => $po->id,
            'store_id' => $mainStore->id,
            'items' => [
                ['po_item_id' => $ricePoItem->id, 'received_quantity' => 10.000],
                ['po_item_id' => $oilPoItem->id, 'received_quantity' => 5.000],
            ],
        ])->assertStatus(201)->json('data.id');

        // Simulate a failure on the 2nd StockLedger insertion (after line 1 stock & ledger were written)
        $insertCount = 0;
        StockLedger::creating(function () use (&$insertCount) {
            $insertCount++;
            if ($insertCount === 2) {
                throw new RuntimeException('Simulated database failure midway through GRN posting');
            }
        });

        try {
            $this->postJson("/api/grns/{$grnId}/approve");
        } catch (\Throwable $_) {
            // Expected exception caught by test runner / handler
        } finally {
            StockLedger::flushEventListeners();
        }

        // Verify complete rollback:
        // 1. GRN status is still Pending and approved_by is null
        $grn = GoodsReceivingNote::findOrFail($grnId);
        $this->assertEquals(GoodsReceivingNote::STATUS_PENDING, $grn->status);
        $this->assertNull($grn->approved_by);

        // 2. PO status is still Sent and received_quantity on both PO items is 0.000
        $this->assertEquals(PurchaseOrder::STATUS_SENT, $po->fresh()->status);
        $this->assertEquals('0.000', $ricePoItem->fresh()->received_quantity);
        $this->assertEquals('0.000', $oilPoItem->fresh()->received_quantity);

        // 3. No CurrentStock or StockLedger records remain
        $this->assertEquals(0, CurrentStock::count());
        $this->assertEquals(0, StockLedger::count());
    }
}
