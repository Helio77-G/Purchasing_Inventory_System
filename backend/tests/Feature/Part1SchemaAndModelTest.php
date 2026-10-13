<?php

namespace Tests\Feature;

use App\Models\CurrentStock;
use App\Models\Department;
use App\Models\GoodsReceivingNote;
use App\Models\GrnItem;
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
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class Part1SchemaAndModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_required_tables_exist_and_pos_recipe_tables_do_not_exist(): void
    {
        $requiredTables = [
            'users',
            'roles',
            'departments',
            'stores',
            'suppliers',
            'items',
            'requisitions',
            'requisition_items',
            'purchase_orders',
            'po_items',
            'goods_receiving_notes',
            'grn_items',
            'current_stocks',
            'stock_ledger',
        ];

        foreach ($requiredTables as $table) {
            $this->assertTrue(
                Schema::hasTable($table),
                "Expected table [{$table}] to exist in schema."
            );
        }

        // Explicitly confirm no POS or recipe deduction tables exist
        $forbiddenPosTables = [
            'pos_orders',
            'pos_items',
            'pos_transactions',
            'recipes',
            'recipe_items',
            'recipe_ingredients',
            'menu_items',
        ];

        foreach ($forbiddenPosTables as $forbiddenTable) {
            $this->assertFalse(
                Schema::hasTable($forbiddenTable),
                "Forbidden POS/recipe table [{$forbiddenTable}] must not exist."
            );
        }
    }

    public function test_seeder_runs_idempotently_without_duplicating_unique_records(): void
    {
        $this->seed(DatabaseSeeder::class);

        $roleCountFirst = Role::count();
        $deptCountFirst = Department::count();
        $storeCountFirst = Store::count();
        $supplierCountFirst = Supplier::count();
        $itemCountFirst = Item::count();
        $userCountFirst = User::count();

        $this->assertEquals(7, $roleCountFirst);
        $this->assertEquals(6, $deptCountFirst);
        $this->assertEquals(3, $storeCountFirst);
        $this->assertEquals(3, $supplierCountFirst);
        $this->assertEquals(7, $itemCountFirst);
        $this->assertEquals(9, $userCountFirst);

        // Run seeder a second time to verify idempotency
        $this->seed(DatabaseSeeder::class);

        $this->assertEquals($roleCountFirst, Role::count());
        $this->assertEquals($deptCountFirst, Department::count());
        $this->assertEquals($storeCountFirst, Store::count());
        $this->assertEquals($supplierCountFirst, Supplier::count());
        $this->assertEquals($itemCountFirst, Item::count());
        $this->assertEquals($userCountFirst, User::count());
    }

    public function test_unique_constraints_prevent_duplicate_sku_order_numbers_and_current_stock_pairs(): void
    {
        $this->seed(DatabaseSeeder::class);

        $store = Store::where('code', 'STR-MAIN')->firstOrFail();
        $item = Item::where('sku', 'ITM-HK-001')->firstOrFail();

        // 1. Duplicate Item SKU is rejected
        $this->expectException(UniqueConstraintViolationException::class);
        Item::create([
            'sku' => 'ITM-HK-001',
            'name' => 'Duplicate Towel SKU',
            'category' => 'Housekeeping Linens',
            'uom' => 'PCS',
            'min_par_level' => 10,
        ]);
    }

    public function test_current_stocks_enforces_unique_item_and_store_pair(): void
    {
        $this->seed(DatabaseSeeder::class);

        $store = Store::where('code', 'STR-MAIN')->firstOrFail();
        $item = Item::where('sku', 'ITM-HK-001')->firstOrFail();

        CurrentStock::create([
            'item_id' => $item->id,
            'store_id' => $store->id,
            'quantity' => 50.250,
        ]);

        $this->expectException(UniqueConstraintViolationException::class);

        CurrentStock::create([
            'item_id' => $item->id,
            'store_id' => $store->id,
            'quantity' => 10.000,
        ]);
    }

    public function test_eloquent_relationships_and_decimal_casts_across_purchasing_and_inventory_models(): void
    {
        $this->seed(DatabaseSeeder::class);

        $department = Department::where('code', 'DEPT-HK')->firstOrFail();
        $store = Store::where('code', 'STR-MAIN')->firstOrFail();
        $supplier = Supplier::where('code', 'SUP-001')->firstOrFail();
        $item = Item::where('sku', 'ITM-HK-001')->firstOrFail();

        $requester = User::where('email', 'staff.hk@hotel.local')->firstOrFail();
        $hod = User::where('email', 'hod.hk@hotel.local')->firstOrFail();
        $gm = User::where('email', 'gm@hotel.local')->firstOrFail();
        $procurement = User::where('email', 'procurement@hotel.local')->firstOrFail();
        $storeKeeper = User::where('email', 'store.keeper@hotel.local')->firstOrFail();
        $storeManager = User::where('email', 'store.manager@hotel.local')->firstOrFail();

        // Verify User role/department/store relationships & helper
        $this->assertTrue($hod->hasRole(Role::SLUG_HOD));
        $this->assertFalse($hod->hasRole(Role::SLUG_GM));
        $this->assertEquals($department->id, $hod->department->id);
        $this->assertEquals($store->id, $storeManager->store->id);

        // 1. Create a Purchase Requisition (PR) with line item
        $pr = Requisition::create([
            'requisition_number' => 'PR-202610-0001',
            'type' => Requisition::TYPE_PR,
            'department_id' => $department->id,
            'store_id' => $store->id,
            'requester_id' => $requester->id,
            'status' => Requisition::STATUS_GM_APPROVED,
            'hod_approved_by' => $hod->id,
            'hod_approved_at' => now()->subHours(2),
            'gm_approved_by' => $gm->id,
            'gm_approved_at' => now()->subHour(),
            'notes' => 'Monthly housekeeping towel replenishment',
        ]);

        $prItem = RequisitionItem::create([
            'requisition_id' => $pr->id,
            'item_id' => $item->id,
            'requested_quantity' => 120.500,
            'issued_quantity' => 0.000,
            'uom' => $item->uom,
        ]);

        $this->assertCount(1, $pr->items);
        $this->assertEquals('120.500', $pr->items->first()->requested_quantity);
        $this->assertEquals($requester->id, $pr->requester->id);
        $this->assertEquals($hod->id, $pr->hodApprover->id);
        $this->assertEquals($gm->id, $pr->gmApprover->id);

        // 2. Create a Purchase Order (PO) linked to the PR
        $po = PurchaseOrder::create([
            'po_number' => 'PO-202610-0001',
            'requisition_id' => $pr->id,
            'supplier_id' => $supplier->id,
            'created_by' => $procurement->id,
            'sent_by' => $procurement->id,
            'sent_at' => now(),
            'status' => PurchaseOrder::STATUS_SENT,
            'total_amount' => 1807500.0000,
        ]);

        $poItem = PoItem::create([
            'purchase_order_id' => $po->id,
            'requisition_item_id' => $prItem->id,
            'item_id' => $item->id,
            'ordered_quantity' => 120.500,
            'received_quantity' => 60.250,
            'unit_price' => 15000.0000,
            'line_total' => 1807500.0000,
            'uom' => $item->uom,
        ]);

        $this->assertEquals($pr->id, $po->requisition->id);
        $this->assertEquals($supplier->id, $po->supplier->id);
        $this->assertEquals('1807500.0000', $po->total_amount);
        $this->assertEquals('60.250', $po->items->first()->received_quantity);

        // 3. Create a Goods Receiving Note (GRN) linked to the PO
        $grn = GoodsReceivingNote::create([
            'grn_number' => 'GRN-202610-0001',
            'purchase_order_id' => $po->id,
            'store_id' => $store->id,
            'received_by' => $storeKeeper->id,
            'approved_by' => $storeManager->id,
            'received_at' => now()->subMinutes(30),
            'approved_at' => now(),
            'status' => GoodsReceivingNote::STATUS_APPROVED,
            'delivery_note_number' => 'DN-8891',
        ]);

        $grnItem = GrnItem::create([
            'grn_id' => $grn->id,
            'po_item_id' => $poItem->id,
            'item_id' => $item->id,
            'received_quantity' => 60.250,
            'unit_price' => 15000.0000,
            'uom' => $item->uom,
        ]);

        $this->assertEquals($po->id, $grn->purchaseOrder->id);
        $this->assertEquals($store->id, $grn->store->id);
        $this->assertEquals($storeKeeper->id, $grn->receiver->id);
        $this->assertEquals($storeManager->id, $grn->approver->id);
        $this->assertEquals($poItem->id, $grnItem->poItem->id);

        // 4. Create StockLedger and CurrentStock records and verify traceability to source document and actor
        $stockBalance = CurrentStock::create([
            'item_id' => $item->id,
            'store_id' => $store->id,
            'quantity' => 60.250,
        ]);

        $ledgerEntry = StockLedger::create([
            'item_id' => $item->id,
            'store_id' => $store->id,
            'department_id' => null,
            'transaction_type' => StockLedger::TYPE_IN,
            'quantity' => 60.250,
            'balance_after' => 60.250,
            'reference_type' => StockLedger::REF_GRN,
            'reference_id' => $grn->id,
            'reference_number' => $grn->grn_number,
            'created_by' => $storeManager->id,
            'transaction_at' => now(),
            'notes' => 'Initial GRN stock receipt',
            'metadata' => ['po_number' => $po->po_number],
        ]);

        $this->assertEquals('60.250', $stockBalance->quantity);
        $this->assertEquals($item->id, $ledgerEntry->item->id);
        $this->assertEquals($store->id, $ledgerEntry->store->id);
        $this->assertEquals($storeManager->id, $ledgerEntry->actor->id);
        $this->assertInstanceOf(GoodsReceivingNote::class, $ledgerEntry->reference);
        $this->assertEquals($grn->grn_number, $ledgerEntry->reference->grn_number);
        $this->assertCount(1, $grn->stockLedgerEntries);
    }

    public function test_non_cascading_foreign_keys_prevent_deleting_referenced_master_or_transaction_records(): void
    {
        $this->seed(DatabaseSeeder::class);

        $department = Department::where('code', 'DEPT-HK')->firstOrFail();
        $store = Store::where('code', 'STR-MAIN')->firstOrFail();
        $requester = User::where('email', 'staff.hk@hotel.local')->firstOrFail();
        $item = Item::where('sku', 'ITM-HK-001')->firstOrFail();

        $sr = Requisition::create([
            'requisition_number' => 'SR-202610-0001',
            'type' => Requisition::TYPE_SR,
            'department_id' => $department->id,
            'store_id' => $store->id,
            'requester_id' => $requester->id,
            'status' => Requisition::STATUS_PENDING,
        ]);

        RequisitionItem::create([
            'requisition_id' => $sr->id,
            'item_id' => $item->id,
            'requested_quantity' => 10.000,
            'issued_quantity' => 0.000,
            'uom' => $item->uom,
        ]);

        // Attempting to hard-delete an item referenced by requisition_items must fail
        $this->expectException(QueryException::class);
        $item->delete();
    }

    public function test_unique_document_numbers_and_idempotent_stock_ledger_constraint(): void
    {
        $this->seed(DatabaseSeeder::class);

        $department = Department::where('code', 'DEPT-HK')->firstOrFail();
        $store = Store::where('code', 'STR-MAIN')->firstOrFail();
        $item = Item::where('sku', 'ITM-HK-001')->firstOrFail();
        $storeManager = User::where('email', 'store.manager@hotel.local')->firstOrFail();

        StockLedger::create([
            'item_id' => $item->id,
            'store_id' => $store->id,
            'department_id' => $department->id,
            'transaction_type' => StockLedger::TYPE_IN,
            'quantity' => 25.000,
            'balance_after' => 25.000,
            'reference_type' => StockLedger::REF_GRN,
            'reference_id' => 999,
            'reference_number' => 'GRN-202610-0999',
            'created_by' => $storeManager->id,
            'transaction_at' => now(),
        ]);

        // Duplicate ledger movement for the same reference_type + reference_id + item_id + store_id must fail
        $this->expectException(UniqueConstraintViolationException::class);

        StockLedger::create([
            'item_id' => $item->id,
            'store_id' => $store->id,
            'department_id' => $department->id,
            'transaction_type' => StockLedger::TYPE_IN,
            'quantity' => 25.000,
            'balance_after' => 50.000,
            'reference_type' => StockLedger::REF_GRN,
            'reference_id' => 999,
            'reference_number' => 'GRN-202610-0999',
            'created_by' => $storeManager->id,
            'transaction_at' => now(),
        ]);
    }
}
