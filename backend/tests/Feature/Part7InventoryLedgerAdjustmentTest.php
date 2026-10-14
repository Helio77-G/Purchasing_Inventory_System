<?php

namespace Tests\Feature;

use App\Models\CurrentStock;
use App\Models\Item;
use App\Models\StockLedger;
use App\Models\StockTake;
use App\Models\Store;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class Part7InventoryLedgerAdjustmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_current_stock_queries_and_low_par_level_flags(): void
    {
        $mainStore = Store::where('code', 'STR-MAIN')->firstOrFail();
        $towelItem = Item::where('sku', 'ITM-HK-001')->firstOrFail(); // min_par_level = 10
        $soapItem = Item::where('sku', 'ITM-HK-002')->firstOrFail();  // min_par_level = 20

        // Set towel above par level (150 > 100)
        CurrentStock::updateOrCreate(
            ['item_id' => $towelItem->id, 'store_id' => $mainStore->id],
            ['quantity' => 150.000]
        );

        // Set soap below par level (5 < 500)
        CurrentStock::updateOrCreate(
            ['item_id' => $soapItem->id, 'store_id' => $mainStore->id],
            ['quantity' => 5.000]
        );

        $admin = User::where('email', 'admin@hotel.local')->firstOrFail();
        Sanctum::actingAs($admin);

        // Query all current stock for main store
        $allRes = $this->getJson("/api/inventory/current-stock?store_id={$mainStore->id}")->assertOk();
        $this->assertCount(2, $allRes->json('data'));

        // Query low stock / below par level filter
        $lowStockRes = $this->getJson("/api/inventory/current-stock?store_id={$mainStore->id}&below_par=1")->assertOk();
        $this->assertCount(1, $lowStockRes->json('data'));
        $this->assertEquals($soapItem->id, $lowStockRes->json('data.0.item_id'));
        $this->assertEquals('5.000', $lowStockRes->json('data.0.quantity'));
    }

    public function test_stock_ledger_filters_and_pagination(): void
    {
        $mainStore = Store::where('code', 'STR-MAIN')->firstOrFail();
        $towelItem = Item::where('sku', 'ITM-HK-001')->firstOrFail();
        $storeManager = User::where('email', 'store.manager@hotel.local')->firstOrFail();

        // Seed 3 ledger entries: 1 IN, 1 OUT, 1 ADJ
        StockLedger::create([
            'item_id' => $towelItem->id,
            'store_id' => $mainStore->id,
            'transaction_type' => StockLedger::TYPE_IN,
            'quantity' => 50.000,
            'balance_after' => 50.000,
            'reference_type' => StockLedger::REF_GRN,
            'reference_id' => 101,
            'reference_number' => 'GRN-TEST-101',
            'created_by' => $storeManager->id,
            'transaction_at' => now()->subHours(3),
        ]);

        StockLedger::create([
            'item_id' => $towelItem->id,
            'store_id' => $mainStore->id,
            'transaction_type' => StockLedger::TYPE_OUT,
            'quantity' => -20.000,
            'balance_after' => 30.000,
            'reference_type' => StockLedger::REF_STOCK_ISSUE,
            'reference_id' => 201,
            'reference_number' => 'ISS-TEST-201',
            'created_by' => $storeManager->id,
            'transaction_at' => now()->subHours(2),
        ]);

        StockLedger::create([
            'item_id' => $towelItem->id,
            'store_id' => $mainStore->id,
            'transaction_type' => StockLedger::TYPE_ADJ,
            'quantity' => -2.000,
            'balance_after' => 28.000,
            'reference_type' => StockLedger::REF_STOCK_ADJUSTMENT,
            'reference_id' => 301,
            'reference_number' => 'ST-TEST-301',
            'created_by' => $storeManager->id,
            'transaction_at' => now()->subHour(),
        ]);

        Sanctum::actingAs($storeManager);

        // 1. Filter by transaction_type = IN
        $inRes = $this->getJson('/api/inventory/ledger?transaction_type=IN')->assertOk();
        $this->assertCount(1, $inRes->json('data'));
        $this->assertEquals('GRN-TEST-101', $inRes->json('data.0.reference_number'));

        // 2. Filter by transaction_type = OUT
        $outRes = $this->getJson('/api/inventory/ledger?transaction_type=OUT')->assertOk();
        $this->assertCount(1, $outRes->json('data'));
        $this->assertEquals('-20.000', $outRes->json('data.0.quantity'));

        // 3. Filter by transaction_type = ADJ
        $adjRes = $this->getJson('/api/inventory/ledger?transaction_type=ADJ')->assertOk();
        $this->assertCount(1, $adjRes->json('data'));
        $this->assertEquals('-2.000', $adjRes->json('data.0.quantity'));

        // 4. Verify no POS-derived movements or tables exist
        $posMovements = StockLedger::where('reference_type', 'like', '%POS%')
            ->orWhere('reference_type', 'like', '%RECIPE%')
            ->count();
        $this->assertEquals(0, $posMovements);
    }

    public function test_physical_stock_take_creation_snapshot_and_variance_calculation(): void
    {
        $mainStore = Store::where('code', 'STR-MAIN')->firstOrFail();
        $towelItem = Item::where('sku', 'ITM-HK-001')->firstOrFail();
        $soapItem = Item::where('sku', 'ITM-HK-002')->firstOrFail();

        CurrentStock::updateOrCreate(
            ['item_id' => $towelItem->id, 'store_id' => $mainStore->id],
            ['quantity' => 40.000]
        );
        CurrentStock::updateOrCreate(
            ['item_id' => $soapItem->id, 'store_id' => $mainStore->id],
            ['quantity' => 25.000]
        );

        $storeKeeper = User::where('email', 'store.keeper@hotel.local')->firstOrFail();
        Sanctum::actingAs($storeKeeper);

        // Initiate stock take session:
        // Towel count: physical = 38 (deficit -2)
        // Soap count: physical = 28 (surplus +3)
        $createRes = $this->postJson('/api/inventory/stock-takes', [
            'store_id' => $mainStore->id,
            'reason' => 'Monthly physical inventory audit',
            'notes' => 'Basement warehouse count',
            'items' => [
                ['item_id' => $towelItem->id, 'physical_quantity' => 38],
                ['item_id' => $soapItem->id, 'physical_quantity' => 28],
            ],
        ])->assertCreated();

        $stockTakeId = $createRes->json('data.id');
        $this->assertEquals(StockTake::STATUS_DRAFT, $createRes->json('data.status'));

        $detailRes = $this->getJson("/api/inventory/stock-takes/{$stockTakeId}")->assertOk();
        $items = collect($detailRes->json('data.items'))->keyBy('item_id');

        // Check towel: system 40, physical 38, variance -2
        $this->assertEquals('40.000', $items->get($towelItem->id)['system_quantity']);
        $this->assertEquals('38.000', $items->get($towelItem->id)['physical_quantity']);
        $this->assertEquals('-2.000', $items->get($towelItem->id)['variance_quantity']);

        // Check soap: system 25, physical 28, variance +3
        $this->assertEquals('25.000', $items->get($soapItem->id)['system_quantity']);
        $this->assertEquals('28.000', $items->get($soapItem->id)['physical_quantity']);
        $this->assertEquals('3.000', $items->get($soapItem->id)['variance_quantity']);
    }

    public function test_stock_adjustment_approval_applies_positive_and_negative_deltas_atomically(): void
    {
        $mainStore = Store::where('code', 'STR-MAIN')->firstOrFail();
        $towelItem = Item::where('sku', 'ITM-HK-001')->firstOrFail();
        $soapItem = Item::where('sku', 'ITM-HK-002')->firstOrFail();

        CurrentStock::updateOrCreate(
            ['item_id' => $towelItem->id, 'store_id' => $mainStore->id],
            ['quantity' => 50.000]
        );
        CurrentStock::updateOrCreate(
            ['item_id' => $soapItem->id, 'store_id' => $mainStore->id],
            ['quantity' => 30.000]
        );

        $storeKeeper = User::where('email', 'store.keeper@hotel.local')->firstOrFail();
        $storeManager = User::where('email', 'store.manager@hotel.local')->firstOrFail();

        Sanctum::actingAs($storeKeeper);
        $stRes = $this->postJson('/api/inventory/stock-takes', [
            'store_id' => $mainStore->id,
            'reason' => 'Mid-month audit',
            'items' => [
                ['item_id' => $towelItem->id, 'physical_quantity' => 45], // deficit -5
                ['item_id' => $soapItem->id, 'physical_quantity' => 35],  // surplus +5
            ],
        ])->assertCreated();

        $stId = $stRes->json('data.id');

        // Verify Draft stock take did not yet change CurrentStock or write StockLedger
        $this->assertEquals('50.000', CurrentStock::where('item_id', $towelItem->id)->where('store_id', $mainStore->id)->value('quantity'));
        $this->assertEquals(0, StockLedger::where('transaction_type', StockLedger::TYPE_ADJ)->count());

        // Store Manager approves adjustment
        Sanctum::actingAs($storeManager);
        $approveRes = $this->postJson("/api/inventory/stock-takes/{$stId}/approve")->assertOk();
        $this->assertEquals(StockTake::STATUS_APPROVED, $approveRes->json('data.status'));

        // Verify CurrentStock updated to physical counts (45.000 and 35.000)
        $this->assertEquals('45.000', CurrentStock::where('item_id', $towelItem->id)->where('store_id', $mainStore->id)->value('quantity'));
        $this->assertEquals('35.000', CurrentStock::where('item_id', $soapItem->id)->where('store_id', $mainStore->id)->value('quantity'));

        // Verify StockLedger ADJ entries
        $adjEntries = StockLedger::where('reference_type', StockLedger::REF_STOCK_ADJUSTMENT)
            ->where('reference_id', $stId)
            ->get()
            ->keyBy('item_id');

        $this->assertCount(2, $adjEntries);
        $this->assertEquals('-5.000', $adjEntries->get($towelItem->id)->quantity);
        $this->assertEquals('45.000', $adjEntries->get($towelItem->id)->balance_after);

        $this->assertEquals('5.000', $adjEntries->get($soapItem->id)->quantity);
        $this->assertEquals('35.000', $adjEntries->get($soapItem->id)->balance_after);

        // Re-approving an already approved stock take must fail (idempotency check)
        $this->postJson("/api/inventory/stock-takes/{$stId}/approve")->assertStatus(422);
    }

    public function test_stale_count_snapshot_is_detected_and_rejected(): void
    {
        $mainStore = Store::where('code', 'STR-MAIN')->firstOrFail();
        $towelItem = Item::where('sku', 'ITM-HK-001')->firstOrFail();

        CurrentStock::updateOrCreate(
            ['item_id' => $towelItem->id, 'store_id' => $mainStore->id],
            ['quantity' => 50.000]
        );

        $storeKeeper = User::where('email', 'store.keeper@hotel.local')->firstOrFail();
        $storeManager = User::where('email', 'store.manager@hotel.local')->firstOrFail();

        // 1. Keeper creates count session when system quantity is 50.000
        Sanctum::actingAs($storeKeeper);
        $stRes = $this->postJson('/api/inventory/stock-takes', [
            'store_id' => $mainStore->id,
            'reason' => 'Audit check',
            'items' => [
                ['item_id' => $towelItem->id, 'physical_quantity' => 48],
            ],
        ])->assertCreated();

        $stId = $stRes->json('data.id');

        // 2. An external stock movement changes CurrentStock to 55.000 before approval
        CurrentStock::where('item_id', $towelItem->id)
            ->where('store_id', $mainStore->id)
            ->update(['quantity' => 55.000]);

        // 3. Attempting to approve the stale count must fail with 422
        Sanctum::actingAs($storeManager);
        $approveRes = $this->postJson("/api/inventory/stock-takes/{$stId}/approve")->assertStatus(422);
        $approveRes->assertJsonValidationErrors(['items']);

        // Stock must remain unchanged at 55.000
        $this->assertEquals(
            '55.000',
            CurrentStock::where('item_id', $towelItem->id)->where('store_id', $mainStore->id)->value('quantity')
        );
    }

    public function test_unauthorized_users_cannot_post_adjustments_and_stock_ledger_is_immutable(): void
    {
        $mainStore = Store::where('code', 'STR-MAIN')->firstOrFail();
        $towelItem = Item::where('sku', 'ITM-HK-001')->firstOrFail();

        CurrentStock::updateOrCreate(
            ['item_id' => $towelItem->id, 'store_id' => $mainStore->id],
            ['quantity' => 20.000]
        );

        $staffHk = User::where('email', 'staff.hk@hotel.local')->firstOrFail();
        $hodHk = User::where('email', 'hod.hk@hotel.local')->firstOrFail();
        $procurement = User::where('email', 'procurement@hotel.local')->firstOrFail();
        $storeKeeper = User::where('email', 'store.keeper@hotel.local')->firstOrFail();

        // 1. Department staff and Procurement cannot create stock take (403)
        foreach ([$staffHk, $hodHk, $procurement] as $unauthorized) {
            Sanctum::actingAs($unauthorized);
            $this->postJson('/api/inventory/stock-takes', [
                'store_id' => $mainStore->id,
                'reason' => 'Unauthorized audit',
                'items' => [
                    ['item_id' => $towelItem->id, 'physical_quantity' => 10],
                ],
            ])->assertForbidden();
        }

        // 2. Store Keeper can create stock take draft, but CANNOT approve adjustment (403)
        Sanctum::actingAs($storeKeeper);
        $createRes = $this->postJson('/api/inventory/stock-takes', [
            'store_id' => $mainStore->id,
            'reason' => 'Keeper count',
            'items' => [
                ['item_id' => $towelItem->id, 'physical_quantity' => 18],
            ],
        ])->assertCreated();

        $stId = $createRes->json('data.id');
        $this->postJson("/api/inventory/stock-takes/{$stId}/approve")->assertForbidden();

        // 3. Confirm that no direct mutation endpoints exist for stock_ledger
        $this->postJson('/api/inventory/ledger', ['quantity' => 100])->assertStatus(405);
        $this->putJson('/api/inventory/ledger/1', ['quantity' => 100])->assertNotFound();
        $this->deleteJson('/api/inventory/ledger/1')->assertNotFound();
    }

    public function test_simulated_failure_during_adjustment_rolls_back_all_changes_atomically(): void
    {
        $mainStore = Store::where('code', 'STR-MAIN')->firstOrFail();
        $towelItem = Item::where('sku', 'ITM-HK-001')->firstOrFail();

        CurrentStock::updateOrCreate(
            ['item_id' => $towelItem->id, 'store_id' => $mainStore->id],
            ['quantity' => 30.000]
        );

        $storeKeeper = User::where('email', 'store.keeper@hotel.local')->firstOrFail();
        $storeManager = User::where('email', 'store.manager@hotel.local')->firstOrFail();

        Sanctum::actingAs($storeKeeper);
        $createRes = $this->postJson('/api/inventory/stock-takes', [
            'store_id' => $mainStore->id,
            'reason' => 'Rollback verification',
            'items' => [
                ['item_id' => $towelItem->id, 'physical_quantity' => 25],
            ],
        ])->assertCreated();

        $stId = $createRes->json('data.id');

        // Simulate database failure during StockLedger creation
        StockLedger::creating(function () {
            throw new RuntimeException('Simulated database crash during Stock Adjustment write.');
        });

        Sanctum::actingAs($storeManager);
        try {
            $this->postJson("/api/inventory/stock-takes/{$stId}/approve");
        } catch (\Throwable $e) {
            // Expected
        } finally {
            StockLedger::flushEventListeners();
        }

        // Verify full rollback: StockTake remains Draft, stock remains 30.000, 0 ADJ ledger rows
        $this->assertEquals(StockTake::STATUS_DRAFT, StockTake::find($stId)->status);
        $this->assertEquals(
            '30.000',
            CurrentStock::where('item_id', $towelItem->id)->where('store_id', $mainStore->id)->value('quantity')
        );
        $this->assertEquals(0, StockLedger::where('transaction_type', StockLedger::TYPE_ADJ)->count());
    }

    public function test_stock_reconciliation_verifies_ledger_sum_matches_current_stocks(): void
    {
        $mainStore = Store::where('code', 'STR-MAIN')->firstOrFail();
        $towelItem = Item::where('sku', 'ITM-HK-001')->firstOrFail();
        $storeManager = User::where('email', 'store.manager@hotel.local')->firstOrFail();

        // Post IN 50, OUT 20 -> balance 30
        StockLedger::create([
            'item_id' => $towelItem->id,
            'store_id' => $mainStore->id,
            'transaction_type' => StockLedger::TYPE_IN,
            'quantity' => 50.000,
            'balance_after' => 50.000,
            'reference_type' => StockLedger::REF_GRN,
            'reference_id' => 901,
            'reference_number' => 'GRN-REC-901',
            'created_by' => $storeManager->id,
            'transaction_at' => now()->subHours(2),
        ]);

        StockLedger::create([
            'item_id' => $towelItem->id,
            'store_id' => $mainStore->id,
            'transaction_type' => StockLedger::TYPE_OUT,
            'quantity' => -20.000,
            'balance_after' => 30.000,
            'reference_type' => StockLedger::REF_STOCK_ISSUE,
            'reference_id' => 902,
            'reference_number' => 'ISS-REC-902',
            'created_by' => $storeManager->id,
            'transaction_at' => now()->subHour(),
        ]);

        CurrentStock::updateOrCreate(
            ['item_id' => $towelItem->id, 'store_id' => $mainStore->id],
            ['quantity' => 30.000]
        );

        Sanctum::actingAs($storeManager);
        $recRes = $this->getJson("/api/inventory/reconciliation?store_id={$mainStore->id}")->assertOk();

        // Confirm reconciliation passes (current stock 30 == ledger sum 50 - 20 = 30)
        $this->assertTrue($recRes->json('data.reconciled'));
        $this->assertEquals(0, $recRes->json('data.discrepancies_count'));

        // Artificially inject a discrepancy in CurrentStock to test discrepancy detection
        CurrentStock::where('item_id', $towelItem->id)
            ->where('store_id', $mainStore->id)
            ->update(['quantity' => 35.000]);

        $recRes2 = $this->getJson("/api/inventory/reconciliation?store_id={$mainStore->id}")->assertOk();
        $this->assertFalse($recRes2->json('data.reconciled'));
        $this->assertEquals(1, $recRes2->json('data.discrepancies_count'));
        $this->assertEquals('35.000', $recRes2->json('data.discrepancies.0.current_stock_quantity'));
        $this->assertEquals('30.000', $recRes2->json('data.discrepancies.0.ledger_sum_quantity'));
    }
}
