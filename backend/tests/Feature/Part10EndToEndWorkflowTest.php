<?php

namespace Tests\Feature;

use App\Models\CurrentStock;
use App\Models\Department;
use App\Models\Item;
use App\Models\PurchaseOrder;
use App\Models\Requisition;
use App\Models\StockIssue;
use App\Models\StockLedger;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class Part10EndToEndWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    /**
     * Workflow A:
     * Department Staff creates SR -> HOD approves -> Store Keeper drafts Issue ->
     * Store Manager posts Issue -> Stock OUT recorded in Ledger -> Current Stock decreases.
     */
    public function test_complete_end_to_end_store_requisition_to_stock_issue_and_stock_out_workflow(): void
    {
        $deptHk = Department::where('code', 'DEPT-HK')->firstOrFail();
        $store = Store::where('code', 'STR-MAIN')->firstOrFail();
        $item = Item::where('sku', 'ITM-HK-001')->firstOrFail();

        $staffHk = User::where('email', 'staff.hk@hotel.local')->firstOrFail();
        $hodHk = User::where('email', 'hod.hk@hotel.local')->firstOrFail();
        $storeKeeper = User::where('email', 'store.keeper@hotel.local')->firstOrFail();
        $storeManager = User::where('email', 'store.manager@hotel.local')->firstOrFail();

        // 1. Initial inventory in warehouse
        CurrentStock::updateOrCreate(
            ['item_id' => $item->id, 'store_id' => $store->id],
            ['quantity' => 60.000]
        );

        // 2. Staff creates SR
        Sanctum::actingAs($staffHk);
        $srRes = $this->postJson('/api/requisitions', [
            'type' => 'SR',
            'department_id' => $deptHk->id,
            'store_id' => $store->id,
            'notes' => 'Towel replenishment for guest floors',
            'items' => [
                ['item_id' => $item->id, 'requested_quantity' => 25.000],
            ],
        ])->assertCreated();

        $srId = $srRes->json('data.id');
        $this->assertEquals(Requisition::STATUS_PENDING, $srRes->json('data.status'));

        // Verify Pending SR has zero stock impact
        $this->assertEquals(
            '60.000',
            CurrentStock::where('item_id', $item->id)->where('store_id', $store->id)->value('quantity')
        );
        $this->assertEquals(0, StockLedger::count());

        // 3. HOD approves SR
        Sanctum::actingAs($hodHk);
        $this->postJson("/api/requisitions/{$srId}/hod-approve")->assertOk();

        // Verify HOD approval has zero stock impact
        $this->assertEquals(
            '60.000',
            CurrentStock::where('item_id', $item->id)->where('store_id', $store->id)->value('quantity')
        );
        $this->assertEquals(0, StockLedger::count());

        // 4. Store Keeper creates Draft Stock Issue for 25 units
        Sanctum::actingAs($storeKeeper);
        $issueRes = $this->postJson('/api/stock-issues', [
            'requisition_id' => $srId,
            'items' => [
                ['item_id' => $item->id, 'issued_quantity' => 25.000],
            ],
        ])->assertCreated();

        $issueId = $issueRes->json('data.id');
        $this->assertEquals(StockIssue::STATUS_DRAFT, $issueRes->json('data.status'));

        // Verify Draft Stock Issue has zero stock impact
        $this->assertEquals(
            '60.000',
            CurrentStock::where('item_id', $item->id)->where('store_id', $store->id)->value('quantity')
        );
        $this->assertEquals(0, StockLedger::count());

        // 5. Store Manager posts Stock Issue
        Sanctum::actingAs($storeManager);
        $postRes = $this->postJson("/api/stock-issues/{$issueId}/post")->assertOk();
        $this->assertEquals(StockIssue::STATUS_POSTED, $postRes->json('data.status'));

        // 6. Verify inventory deductions & Stock Ledger OUT movement
        $this->assertEquals(
            '35.000', // 60 - 25 = 35
            CurrentStock::where('item_id', $item->id)->where('store_id', $store->id)->value('quantity')
        );

        $ledgerEntry = StockLedger::where('reference_type', StockLedger::REF_STOCK_ISSUE)
            ->where('reference_id', $issueId)
            ->firstOrFail();

        $this->assertEquals(StockLedger::TYPE_OUT, $ledgerEntry->transaction_type);
        $this->assertEquals('-25.000', $ledgerEntry->quantity);
        $this->assertEquals('35.000', $ledgerEntry->balance_after);
        $this->assertEquals($deptHk->id, $ledgerEntry->department_id); // Cost center attribution
        $this->assertEquals($storeManager->id, $ledgerEntry->created_by);

        // SR status becomes fully Issued
        $this->assertEquals(Requisition::STATUS_ISSUED, Requisition::find($srId)->status);
    }

    /**
     * Workflow B:
     * Staff creates PR -> HOD approves -> GM approves -> Procurement creates & sends PO ->
     * Partial GRN 1 approved -> Stock IN -> PO becomes Partially Received ->
     * Final GRN 2 approved -> Stock IN -> PO becomes Fully Received.
     */
    public function test_complete_end_to_end_purchase_requisition_to_po_to_partial_and_final_grn_workflow(): void
    {
        $deptHk = Department::where('code', 'DEPT-HK')->firstOrFail();
        $store = Store::where('code', 'STR-MAIN')->firstOrFail();
        $supplier = Supplier::where('code', 'SUP-001')->firstOrFail();
        $item = Item::where('sku', 'ITM-HK-002')->firstOrFail();

        $staffHk = User::where('email', 'staff.hk@hotel.local')->firstOrFail();
        $hodHk = User::where('email', 'hod.hk@hotel.local')->firstOrFail();
        $gm = User::where('email', 'gm@hotel.local')->firstOrFail();
        $procurement = User::where('email', 'procurement@hotel.local')->firstOrFail();
        $storeKeeper = User::where('email', 'store.keeper@hotel.local')->firstOrFail();
        $storeManager = User::where('email', 'store.manager@hotel.local')->firstOrFail();

        // 1. Staff creates PR for 50 bottles
        Sanctum::actingAs($staffHk);
        $prRes = $this->postJson('/api/requisitions', [
            'type' => 'PR',
            'department_id' => $deptHk->id,
            'items' => [
                ['item_id' => $item->id, 'requested_quantity' => 50],
            ],
        ])->assertCreated();
        $prId = $prRes->json('data.id');

        // 2. HOD approves PR
        Sanctum::actingAs($hodHk);
        $this->postJson("/api/requisitions/{$prId}/hod-approve")->assertOk();

        // 3. GM approves PR
        Sanctum::actingAs($gm);
        $this->postJson("/api/requisitions/{$prId}/gm-approve")->assertOk();

        // 4. Procurement creates PO from GM-approved PR
        Sanctum::actingAs($procurement);
        $poRes = $this->postJson('/api/purchase-orders', [
            'requisition_id' => $prId,
            'supplier_id' => $supplier->id,
            'items' => [
                ['item_id' => $item->id, 'ordered_quantity' => 50, 'unit_price' => 2000],
            ],
        ])->assertCreated();
        $poId = $poRes->json('data.id');

        // Send PO to Supplier
        $this->postJson("/api/purchase-orders/{$poId}/send")->assertOk();
        $this->assertEquals(PurchaseOrder::STATUS_SENT, PurchaseOrder::find($poId)->status);

        // Verify PO creation and send has zero stock impact
        $this->assertNull(CurrentStock::where('item_id', $item->id)->where('store_id', $store->id)->first());
        $this->assertEquals(0, StockLedger::count());

        // 5. Supplier delivers 30 units (Partial Delivery #1)
        Sanctum::actingAs($storeKeeper);
        $grn1Res = $this->postJson('/api/grns', [
            'purchase_order_id' => $poId,
            'store_id' => $store->id,
            'delivery_note_number' => 'DN-001',
            'items' => [
                ['item_id' => $item->id, 'received_quantity' => 30],
            ],
        ])->assertCreated();
        $grn1Id = $grn1Res->json('data.id');

        // Store Manager approves GRN #1
        Sanctum::actingAs($storeManager);
        $this->postJson("/api/grns/{$grn1Id}/approve")->assertOk();

        // Verify Stock IN #1 and PO status Partially Received
        $this->assertEquals(
            '30.000',
            CurrentStock::where('item_id', $item->id)->where('store_id', $store->id)->value('quantity')
        );
        $this->assertEquals(PurchaseOrder::STATUS_PARTIAL_RECEIVED, PurchaseOrder::find($poId)->status);

        // 6. Supplier delivers remaining 20 units (Final Delivery #2)
        Sanctum::actingAs($storeKeeper);
        $grn2Res = $this->postJson('/api/grns', [
            'purchase_order_id' => $poId,
            'store_id' => $store->id,
            'delivery_note_number' => 'DN-002',
            'items' => [
                ['item_id' => $item->id, 'received_quantity' => 20],
            ],
        ])->assertCreated();
        $grn2Id = $grn2Res->json('data.id');

        // Store Manager approves GRN #2
        Sanctum::actingAs($storeManager);
        $this->postJson("/api/grns/{$grn2Id}/approve")->assertOk();

        // Verify Stock IN #2 and PO status Fully Received
        $this->assertEquals(
            '50.000',
            CurrentStock::where('item_id', $item->id)->where('store_id', $store->id)->value('quantity')
        );
        $this->assertEquals(PurchaseOrder::STATUS_FULLY_RECEIVED, PurchaseOrder::find($poId)->status);

        // Verify 2 StockLedger IN movements
        $this->assertEquals(2, StockLedger::where('transaction_type', StockLedger::TYPE_IN)->count());
    }

    /**
     * Workflow C:
     * Store Keeper initiates Stock Take -> Counts items -> Variance computed ->
     * Store Manager approves Adjustment -> ADJ Ledger posted -> Current Stock reconciles 100%.
     */
    public function test_complete_end_to_end_stock_take_to_adjustment_and_mathematical_reconciliation(): void
    {
        $store = Store::where('code', 'STR-MAIN')->firstOrFail();
        $item1 = Item::where('sku', 'ITM-HK-001')->firstOrFail();
        $item2 = Item::where('sku', 'ITM-HK-002')->firstOrFail();

        $storeKeeper = User::where('email', 'store.keeper@hotel.local')->firstOrFail();
        $storeManager = User::where('email', 'store.manager@hotel.local')->firstOrFail();

        // Seed initial stocks with matching ledger entries
        CurrentStock::updateOrCreate(['item_id' => $item1->id, 'store_id' => $store->id], ['quantity' => 50.000]);
        StockLedger::create([
            'item_id' => $item1->id,
            'store_id' => $store->id,
            'transaction_type' => StockLedger::TYPE_IN,
            'quantity' => 50.000,
            'balance_after' => 50.000,
            'reference_type' => StockLedger::REF_GRN,
            'reference_id' => 881,
            'reference_number' => 'GRN-INIT-01',
            'created_by' => $storeManager->id,
            'transaction_at' => now()->subDay(),
        ]);

        CurrentStock::updateOrCreate(['item_id' => $item2->id, 'store_id' => $store->id], ['quantity' => 30.000]);
        StockLedger::create([
            'item_id' => $item2->id,
            'store_id' => $store->id,
            'transaction_type' => StockLedger::TYPE_IN,
            'quantity' => 30.000,
            'balance_after' => 30.000,
            'reference_type' => StockLedger::REF_GRN,
            'reference_id' => 882,
            'reference_number' => 'GRN-INIT-02',
            'created_by' => $storeManager->id,
            'transaction_at' => now()->subDay(),
        ]);

        // 1. Initial reconciliation check passes
        Sanctum::actingAs($storeManager);
        $initRec = $this->getJson("/api/inventory/reconciliation?store_id={$store->id}")->assertOk();
        $this->assertTrue($initRec->json('data.reconciled'));

        // 2. Physical count: item1 has 47 (deficit -3), item2 has 34 (surplus +4)
        Sanctum::actingAs($storeKeeper);
        $stRes = $this->postJson('/api/inventory/stock-takes', [
            'store_id' => $store->id,
            'reason' => 'Annual physical count audit',
            'items' => [
                ['item_id' => $item1->id, 'physical_quantity' => 47],
                ['item_id' => $item2->id, 'physical_quantity' => 34],
            ],
        ])->assertCreated();
        $stId = $stRes->json('data.id');

        // 3. Store Manager approves adjustment
        Sanctum::actingAs($storeManager);
        $this->postJson("/api/inventory/stock-takes/{$stId}/approve")->assertOk();

        // 4. Verify balances updated to physical count
        $this->assertEquals('47.000', CurrentStock::where('item_id', $item1->id)->where('store_id', $store->id)->value('quantity'));
        $this->assertEquals('34.000', CurrentStock::where('item_id', $item2->id)->where('store_id', $store->id)->value('quantity'));

        // 5. Verify mathematical reconciliation against stock ledger
        // item1: 50 (IN) - 3 (ADJ) = 47.000
        // item2: 30 (IN) + 4 (ADJ) = 34.000
        $finalRec = $this->getJson("/api/inventory/reconciliation?store_id={$store->id}")->assertOk();
        $this->assertTrue($finalRec->json('data.reconciled'));
        $this->assertEquals(0, $finalRec->json('data.discrepancies_count'));
    }

    /**
     * Requirement: Confirm the application has zero POS integration or recipe-based deductions.
     */
    public function test_no_pos_or_recipe_deduction_leakage_across_entire_system(): void
    {
        $forbiddenTables = [
            'pos_orders',
            'pos_items',
            'pos_transactions',
            'recipes',
            'recipe_items',
            'recipe_ingredients',
            'menu_items',
        ];

        foreach ($forbiddenTables as $table) {
            $this->assertFalse(
                Schema::hasTable($table),
                "Forbidden table [{$table}] must not exist."
            );
        }

        $forbiddenLedgerMovements = StockLedger::where('reference_type', 'like', '%POS%')
            ->orWhere('reference_type', 'like', '%RECIPE%')
            ->count();

        $this->assertEquals(0, $forbiddenLedgerMovements);
    }
}
